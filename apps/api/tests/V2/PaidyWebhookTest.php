<?php

namespace Tests\V2;

use App\Domain\Outbox\Services\V2OutboxService;
use App\Domain\Payment\V2\Services\V2PaidyReconciliationService;
use App\Domain\Payment\V2\Services\V2PaidyWebhookService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;
use Tests\V2\Fixtures\PaidyFixtures;

final class PaidyWebhookTest extends TestCase
{
    use PaidyFixtures;
    protected function setUp(): void { parent::setUp(); $this->configurePaidy(); }
    protected function tearDown(): void { $this->quiescePaidyFixtureJobs(); parent::tearDown(); }

    public function test_webhook_authentication_durable_receipt_deduplication_and_async_worker(): void
    {
        [, $payment] = $this->paidyPayment(); $body = $this->paidyObject($payment, 'CLOSED');
        $input = ['payment_id' => $body['id'], 'event_type' => 'payment', 'status' => 'capture_success',
            'order_ref' => 'untrusted-browser-reference', 'amount' => 1, 'email' => 'untrusted@example.test'];
        $this->postJson('/webhooks/v2/paidy/wrong', $input)->assertNotFound();
        $url = '/webhooks/v2/paidy/'.config('v2_paidy.webhook_path_token');
        $before = DB::table('payment_provider_events')->count();
        $this->postJson($url, $input)->assertOk(); $this->postJson($url, $input)->assertOk();
        Http::assertNothingSent();
        self::assertSame($before + 1, DB::table('payment_provider_events')->count());
        self::assertSame(0, DB::table('payment_point_grants')->where('payment_id', $payment->id)->count());
        $stored = json_encode(DB::table('outbox_messages')->where('topic', 'payment.paidy')->get());
        self::assertStringNotContainsString('untrusted@example.test', $stored);
        self::assertStringNotContainsString('untrusted-browser-reference', $stored);
        Http::swap(new \Illuminate\Http\Client\Factory());
        Http::fake(['https://api.paidy.com/*' => Http::response($body)]);
        config(['v2_paidy.worker_enabled' => true]);
        app(V2PaidyReconciliationService::class)->work(100);
        self::assertSame(1, DB::table('payment_point_grants')->where('payment_id', $payment->id)->count());
        self::assertSame(1, DB::table('mail_deliveries')->where('source_public_id', $payment->public_id)->count());
        // Later reversals wake a completed lookup, with no automatic point debit.
        $body['refunds'] = [['id' => 'ref_synthetic', 'amount' => 1000]];
        app(V2PaidyWebhookService::class)->receive([...$input, 'status' => 'refund_success']);
        Http::swap(new \Illuminate\Http\Client\Factory());
        Http::fake(['https://api.paidy.com/*' => Http::response($body)]);
        app(V2PaidyReconciliationService::class)->work(100);
        self::assertSame('succeeded', DB::table('payments')->where('id', $payment->id)->value('status'));
        self::assertSame(1000, (int) DB::table('wallets')->where('user_id', $payment->user_id)->value('paid_balance'));
        self::assertTrue(DB::table('payment_progress_events')->where('payment_id', $payment->id)->whereRaw("detail->>'reason' = 'provider_reversed'")->exists());
    }

    public function test_unknown_notification_is_minimal_and_persistence_failure_is_not_acknowledged(): void
    {
        $url = '/webhooks/v2/paidy/'.config('v2_paidy.webhook_path_token');
        $this->postJson($url, ['future_field' => 'untrusted@example.test'])->assertOk();
        Http::assertNothingSent();
        // A durable-key conflict must roll back the receipt and return 503.
        DB::transaction(fn () => app(V2OutboxService::class)->enqueue('payment.paidy', 'payment', null, 'paidy.lookup',
            ['paidy_payment_id' => 'pay_wrong_payload', 'payment_id' => null], 'paidy.notification:'.hash('sha256', json_encode(['pay_persistence_failure', 'unknown', null]))));
        $this->postJson($url, ['payment_id' => 'pay_persistence_failure'])->assertStatus(503);
    }

    public function test_worker_is_off_by_default_and_claims_no_other_provider_topic(): void
    {
        self::assertFalse(config('v2_paidy.worker_enabled'));
        $this->artisan('v2:paidy:work')->assertExitCode(1);
        DB::transaction(fn () => app(V2OutboxService::class)->enqueue('payment.univapay', 'payment', null, 'univapay.lookup', ['charge_id' => 'synthetic'], 'paidy-test-other-provider'));
        config(['v2_paidy.worker_enabled' => true]);
        app(V2PaidyReconciliationService::class)->work(1);
        self::assertSame(0, (int) DB::table('outbox_messages')->where('deduplication_key', 'paidy-test-other-provider')->value('attempts'));
    }

    public function test_worker_recovers_missing_callback_from_verified_webhook_id(): void
    {
        [, $payment] = $this->paidyPayment(); $authorized = $this->paidyObject($payment); $closed = $this->paidyObject($payment, 'CLOSED');
        Http::fakeSequence()->push($authorized)->push($closed, 201);
        app(V2PaidyWebhookService::class)->receive(['payment_id' => $authorized['id'], 'status' => 'authorize_success']);
        config(['v2_paidy.worker_enabled' => true]);
        app(V2PaidyReconciliationService::class)->work(100);
        self::assertSame('succeeded', DB::table('payments')->where('id', $payment->id)->value('status'));
        self::assertSame(1, DB::table('payment_point_grants')->where('payment_id', $payment->id)->count());
    }
}
