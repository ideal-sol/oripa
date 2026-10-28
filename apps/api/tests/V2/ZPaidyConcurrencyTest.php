<?php

namespace Tests\V2;

use App\Domain\Payment\V2\Services\V2PaidyPaymentService;
use App\Domain\Payment\V2\Services\V2PaidyReconciliationService;
use App\Domain\Payment\V2\Services\V2PaidyWebhookService;
use App\Models\V2\User;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;
use Tests\V2\Fixtures\PaidyFixtures;

final class ZPaidyConcurrencyTest extends TestCase
{
    use PaidyFixtures;
    protected function tearDown(): void { $this->quiescePaidyFixtureJobs(); parent::tearDown(); }
    public static function tearDownAfterClass(): void { self::resetPaidyTestDatabase(); parent::tearDownAfterClass(); }

    public function test_callback_webhook_and_worker_race_sends_one_capture_and_grants_once(): void
    {
        self::assertTrue(function_exists('pcntl_fork'));
        $this->configurePaidy();
        [$user, $payment] = $this->paidyPayment(); $authorized = $this->paidyObject($payment); $closed = $this->paidyObject($payment, 'CLOSED');
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
}
