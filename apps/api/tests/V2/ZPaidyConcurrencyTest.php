<?php

namespace Tests\V2;

use App\Domain\Payment\V2\Services\V2PaidyPaymentService;
use App\Domain\Payment\V2\Services\V2PaidyReconciliationService;
use App\Domain\Payment\V2\Services\V2PaidyWebhookService;
use App\Models\V2\User;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;
use Tests\V2\Fixtures\PaidyFixtures;

final class ZPaidyConcurrencyTest extends TestCase
{
    use PaidyFixtures;
    use \Tests\V2\Fixtures\PaidyFinancialAdmissionFixture;
    protected function tearDown(): void { $this->quiescePaidyFixtureJobs(); \Carbon\Carbon::setTestNow(); \Carbon\CarbonImmutable::setTestNow(); parent::tearDown(); }
    public static function tearDownAfterClass(): void { self::resetPaidyTestDatabase(); parent::tearDownAfterClass(); }

    #[\PHPUnit\Framework\Attributes\DataProvider('captureTimes')]
    public function test_callback_webhook_and_worker_race_sends_one_capture_and_grants_once(int $minutes): void
    {
        self::assertTrue(function_exists('pcntl_fork'));
        $this->requirePaidyFinancialAdmission();
        $this->configurePaidy();
        [$user, $payment] = $this->paidyPayment();
        \Carbon\Carbon::setTestNow(now()->addMinutes($minutes));
        \Carbon\CarbonImmutable::setTestNow(\Carbon\Carbon::now());
        $authorized = $this->paidyObject($payment); $closed = $this->paidyObject($payment, 'CLOSED');
        $captureLog = tempnam(sys_get_temp_dir(), 'paidy-captures-');
        $results = tempnam(sys_get_temp_dir(), 'paidy-results-');
        try {
            Http::fake(function (Request $request) use ($authorized, $closed, $captureLog) {
                self::assertSame(0, DB::transactionLevel());
                if ($request->method() === 'POST') {
                    file_put_contents($captureLog, "capture\n", FILE_APPEND | LOCK_EX);
                    usleep(150000);
                    return Http::response($closed, 201);
                }
                return Http::response(filesize($captureLog) > 0 ? $closed : $authorized);
            });
            config(['v2_paidy.worker_enabled' => true]);
            app(V2PaidyWebhookService::class)->receive(['payment_id' => $authorized['id'], 'status' => 'authorize_success']);
            DB::disconnect(); $children = [];
            foreach (range(1, 4) as $index) {
                $pid = pcntl_fork(); self::assertNotSame(-1, $pid);
                if ($pid === 0) {
                    DB::purge(); DB::reconnect();
                    try {
                        self::assertSame(1, (int) DB::table('financial_admission_generations')->value('generation'));
                        if ($index % 2 === 0) app(V2PaidyReconciliationService::class)->work(10);
                        else app(V2PaidyPaymentService::class)->authorize(User::query()->findOrFail($user->id), $payment->public_id,
                            ['paidy_payment_id' => $authorized['id']], 'race-'.$index);
                        file_put_contents($results, "ok\n", FILE_APPEND | LOCK_EX);
                    } catch (\Throwable $e) { file_put_contents($results, get_class($e)."\n", FILE_APPEND | LOCK_EX); }
                    DB::disconnect(); exit(0);
                }
                $children[] = $pid;
            }
            foreach ($children as $pid) { pcntl_waitpid($pid, $status); self::assertTrue(pcntl_wifexited($status)); self::assertSame(0, pcntl_wexitstatus($status)); }
            DB::reconnect();
            self::assertSame(["ok", "ok", "ok", "ok"], file($results, FILE_IGNORE_NEW_LINES));
            self::assertSame(["capture"], file($captureLog, FILE_IGNORE_NEW_LINES));
            self::assertSame('succeeded', DB::table('payments')->where('id', $payment->id)->value('status'));
            self::assertSame(1, DB::table('payment_point_grants')->where('payment_id', $payment->id)->count());
            self::assertSame(1, DB::table('mail_deliveries')->where('source_public_id', $payment->public_id)->count());
            self::assertSame(1, DB::table('payment_receipt_snapshots')->where('payment_id', $payment->id)->count());
            self::assertSame(1000, (int) DB::table('wallets')->where('user_id', $user->id)->value('paid_balance'));
        } finally { @unlink($captureLog); @unlink($results); DB::reconnect(); }
    }

    public static function captureTimes(): array { return [[0], [70]]; }

    #[\PHPUnit\Framework\Attributes\DataProvider('checkoutRequestOrder')]
    public function test_authorization_and_abandonment_wait_on_the_same_payment_row(bool $authorizeFirst): void
    {
        $this->configurePaidy();
        [$user, $payment] = $this->paidyPayment();
        $body = $this->paidyObject($payment);
        // Keep the provider result unassociated so only durable acceptance can block abandonment.
        $body['order']['order_ref'] = (string) Str::uuid();
        Http::fake(['https://api.paidy.com/*' => Http::response($body)]);
        $run = static function (bool $authorize) use ($user, $payment, $body): string {
            $service = app(V2PaidyPaymentService::class);
            $owner = User::query()->findOrFail($user->id);
            $response = $authorize
                ? $service->authorize($owner, $payment->public_id, ['paidy_payment_id' => $body['id']], 'concurrent-authorize')
                : $service->abandon($owner, $payment->public_id, ['result' => 'closed'], 'concurrent-abandon');

            return $response['status'];
        };
        $sockets = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        self::assertNotFalse($sockets);
        [$parent, $child] = $sockets;
        DB::disconnect();
        $pid = pcntl_fork();
        self::assertNotSame(-1, $pid);
        if ($pid === 0) {
            fclose($parent);
            stream_set_timeout($child, 10);
            try {
                DB::statement("SET application_name = 'paidy_abandon_concurrent_second'");
                if (fgets($child) !== "start\n") exit(1);
                fwrite($child, $run(! $authorizeFirst)."\n");
            } catch (\Throwable $exception) {
                fwrite($child, get_class($exception)."\n");
                exit(1);
            }
            DB::disconnect();
            exit(0);
        }
        fclose($child);
        stream_set_timeout($parent, 10);
        $firstLock = true;
        DB::listen(function (QueryExecuted $query) use ($parent, &$firstLock): void {
            if (! $firstLock || ! str_contains($query->sql, 'from "payments"') || ! str_ends_with($query->sql, 'for update')) {
                return;
            }
            $firstLock = false;
            fwrite($parent, "start\n");
            $deadline = microtime(true) + 5;
            do {
                DB::statement('SELECT pg_stat_clear_snapshot()');
                $waiting = DB::selectOne("SELECT EXISTS (
                    SELECT 1 FROM pg_stat_activity WHERE application_name = 'paidy_abandon_concurrent_second'
                    AND wait_event_type = 'Lock' AND query LIKE '%payments%for update'
                ) AS waiting")->waiting;
                if ($waiting) return;
                usleep(10000);
            } while (microtime(true) < $deadline);
            self::fail('The second checkout request did not wait for the payment row lock.');
        });
        try {
            $expected = $authorizeFirst ? 'requires_action' : 'canceled';
            self::assertSame($expected, $run($authorizeFirst));
            self::assertFalse($firstLock);
            self::assertSame($expected."\n", fgets($parent));
            $this->assertDatabaseHas('payments', ['id' => $payment->id, 'status' => $expected]);
            $this->assertDatabaseHas('outbox_messages', [
                'aggregate_public_id' => $payment->public_id, 'event_type' => 'paidy.lookup',
            ]);
        } finally {
            fclose($parent);
            pcntl_waitpid($pid, $status);
        }
        self::assertTrue(pcntl_wifexited($status));
        self::assertSame(0, pcntl_wexitstatus($status));
    }

    public static function checkoutRequestOrder(): array { return [[true], [false]]; }
}
