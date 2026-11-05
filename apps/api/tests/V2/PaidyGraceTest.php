<?php

namespace Tests\V2;

use App\Domain\Payment\V2\Services\V2PaidyDeadline;
use App\Domain\Payment\V2\Services\V2PaidyPaymentService;
use App\Domain\Payment\V2\Services\V2PaidyReconciliationService;
use App\Domain\Payment\V2\Services\V2PaidyWebhookService;
use App\Support\V2DatabaseTimestamp;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;
use Tests\V2\Fixtures\PaidyFixtures;

final class PaidyGraceTest extends TestCase
{
    use PaidyFixtures;

    protected function setUp(): void { parent::setUp(); $this->configurePaidy(); $this->clock('2026-11-05T00:00:00Z'); }
    protected function tearDown(): void { $this->quiescePaidyFixtureJobs(); Carbon::setTestNow(); CarbonImmutable::setTestNow(); parent::tearDown(); }

    private function clock(string $time): void { Carbon::setTestNow($time); CarbonImmutable::setTestNow($time); }

    #[DataProvider('gracePaths')]
    public function test_grace_capture_and_grant_once_across_entry_paths(string $status, string $path): void
    {
        [$user, $payment] = $this->paidyPayment();
        DB::table('payments')->where('id', $payment->id)->update(['status' => $status]);
        $this->clock('2026-11-05T01:10:00Z');
        self::assertNull(app(V2PaidyPaymentService::class)->action($payment));
        $authorized = $this->paidyObject($payment); $closed = $this->paidyObject($payment, 'CLOSED'); $captures = 0;
        Http::fake(function (Request $request) use ($authorized, $closed, &$captures) {
            if ($request->method() === 'POST') { self::assertStringEndsWith('/captures', $request->url()); $captures++; }
            return Http::response($captures ? $closed : $authorized);
        });
        if ($path === 'api') $this->authorizePaidy($user, $payment, $authorized['id']);
        else {
            config(['v2_paidy.worker_enabled' => true]);
            if ($path === 'webhook') app(V2PaidyWebhookService::class)->receive(['payment_id' => $authorized['id'], 'status' => 'authorize_success']);
            else DB::table('payments')->where('id', $payment->id)->update(['provider_payment_id' => $authorized['id']]);
            app(V2PaidyReconciliationService::class)->work(100);
        }
        $this->authorizePaidy($user, $payment, $authorized['id']);
        self::assertSame('succeeded', DB::table('payments')->where('id', $payment->id)->value('status'));
        self::assertSame(1, $captures);
        self::assertSame(1, DB::table('payment_point_grants')->where('payment_id', $payment->id)->count());
        self::assertSame(1000, (int) DB::table('wallets')->where('user_id', $user->id)->value('paid_balance'));
    }

    public static function gracePaths(): array
    {
        return [['requires_action', 'api'], ['processing', 'api'], ['requires_action', 'webhook'], ['processing', 'webhook'], ['requires_action', 'worker'], ['processing', 'worker']];
    }

    #[DataProvider('deadlines')]
    public function test_no_capture_at_or_after_deadline(string $status, int $grace, string $time): void
    {
        config(['v2_paidy.authorization_grace_minutes' => $grace]);
        [$user, $payment] = $this->paidyPayment();
        DB::table('payments')->where('id', $payment->id)->update(['status' => $status]);
        $this->clock($time); $body = $this->paidyObject($payment);
        Http::fake(fn (Request $request) => Http::response($request->method() === 'POST' ? [...$body, 'status' => 'CLOSED'] : $body));
        $this->authorizePaidy($user, $payment, $body['id']);
        Http::assertNotSent(fn (Request $r) => str_ends_with($r->url(), '/captures'));
        self::assertSame(0, DB::table('payment_point_grants')->where('payment_id', $payment->id)->count());
        if ($status === 'processing') {
            Http::assertNotSent(fn (Request $r) => $r->method() === 'POST');
            self::assertSame('processing', DB::table('payments')->where('id', $payment->id)->value('status'));
        }
    }

    public static function deadlines(): array
    {
        return [['requires_action', 30, '2026-11-05T01:30:00Z'], ['processing', 30, '2026-11-05T01:30:00Z'], ['requires_action', 30, '2026-11-05T01:31:00Z'], ['requires_action', 0, '2026-11-05T01:00:00Z'], ['expired', 30, '2026-11-05T01:10:00Z'], ['failed', 30, '2026-11-05T01:10:00Z'], ['canceled', 30, '2026-11-05T01:10:00Z']];
    }

    public function test_worker_waits_until_exact_grace_end_without_extending_checkout(): void
    {
        [, $payment] = $this->paidyPayment(); config(['v2_paidy.worker_enabled' => true]);
        $this->clock('2026-11-05T01:29:59Z');
        app(V2PaidyReconciliationService::class)->work(100);
        self::assertSame('requires_action', DB::table('payments')->where('id', $payment->id)->value('status'));
        self::assertNull(app(V2PaidyPaymentService::class)->action($payment));
        $this->clock('2026-11-05T01:30:00Z');
        DB::table('outbox_messages')->where('deduplication_key', 'paidy.payment:'.$payment->public_id)->update(['available_at' => V2DatabaseTimestamp::format(now())]);
        app(V2PaidyReconciliationService::class)->work(100);
        self::assertSame('expired', DB::table('payments')->where('id', $payment->id)->value('status'));
        Http::assertNothingSent();
    }

    public function test_capture_occurring_at_grace_end_or_without_claim_is_not_granted(): void
    {
        foreach ([false, true] as $claim) {
            $this->clock('2026-11-05T00:00:00Z'); [$user, $payment] = $this->paidyPayment();
            $this->clock('2026-11-05T01:29:00Z');
            $authorized = $this->paidyObject($payment);
            Http::swap(new \Illuminate\Http\Client\Factory());
            Http::fake(function (Request $request) use ($payment, $authorized, $claim) {
                if ($claim && $request->method() !== 'POST') return Http::response($authorized);
                if ($claim) $this->clock('2026-11-05T01:30:00Z');
                return Http::response($this->paidyObject($payment, 'CLOSED'));
            });
            $this->authorizePaidy($user, $payment, $authorized['id']);
            self::assertSame(0, DB::table('payment_point_grants')->where('payment_id', $payment->id)->count());
        }
    }

    public function test_invalid_grace_fails_closed_and_valid_range_is_bounded(): void
    {
        $payment = (object) ['expires_at' => '2026-11-05T01:00:00Z', 'status' => 'requires_action'];
        foreach ([-1, 121, 'invalid', '', null, true, 1.5, '1e2'] as $value) {
            config(['v2_paidy.authorization_grace_minutes' => $value]);
            self::assertSame('01:00', V2PaidyDeadline::authorization($payment)->format('H:i'));
        }
        config(['v2_paidy.authorization_grace_minutes' => '120']);
        self::assertSame('03:00', V2PaidyDeadline::authorization($payment)->format('H:i'));
    }
}
