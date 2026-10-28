<?php

namespace Tests\V2;

use App\Domain\Identity\Services\V2UserClosureService;
use App\Domain\Payment\V2\Services\V2AdminPaymentAdjustmentService;
use App\Domain\Payment\V2\Services\V2PaymentService;
use App\Domain\Point\Services\V2PointLedgerService;
use App\Domain\Point\Services\V2PointTransactionRunner;
use App\Models\V2\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\Support\MemberRankAccountingFixture;
use Tests\TestCase;

final class ZAccountClosureConcurrencyTest extends TestCase
{
    use MemberRankAccountingFixture;

    public function test_committed_payment_refund_and_two_actor_closure_races_preserve_accounting(): void
    {
        self::assertTrue(app()->environment('testing'));
        self::assertTrue(function_exists('pcntl_fork'));
        $this->configureRankFixture();
        config(['mail.default' => 'array']);
        Mail::fake();
        try {
            $user = $this->rankUser();
            [$payment, $event] = $this->rankPayment($user, 12500);
            $this->race([
                fn () => $this->close($user->id, 'self'),
                fn () => app(V2PaymentService::class)->confirmSucceeded($event->id),
            ]);
            self::assertSame(1, DB::table('account_closures')->where('user_id', $user->id)->count());
            self::assertSame(['paid' => 0, 'free' => 0], app(V2PointLedgerService::class)->rebuild($user->id));
            $granted = DB::table('payment_point_grants')->where('payment_id', $payment->id)->count();
            self::assertSame(1, $granted + DB::table('account_closure_late_payments')->where('payment_id', $payment->id)->count());
            self::assertSame($granted ? 12500 : 0, (int) DB::table('account_closures')->where('user_id', $user->id)->value('forfeited_points'));

            $user = $this->rankUser();
            $payment = $this->settleRankPayment($user, 2000);
            $admin = $this->rankAdmin();
            $this->race([
                fn () => $this->close($user->id, 'self'),
                fn () => app(V2AdminPaymentAdjustmentService::class)->write($admin, $payment->public_id, 'refund', ['reason' => 'Synthetic race'], (string) Str::uuid7()),
            ]);
            self::assertDatabaseHas('users', ['id' => $user->id, 'state' => 'suspended']);
            self::assertDatabaseHas('wallets', ['user_id' => $user->id, 'paid_balance' => 2000, 'paid_reserved_balance' => 2000]);
            self::assertSame(0, app(V2UserClosureService::class)->preview($user)['point_balance']);
            self::assertSame(1, DB::table('payment_adjustments')->where('payment_id', $payment->id)->count());

            $user = $this->rankUser();
            $this->settleRankPayment($user, 12500);
            $this->race([fn () => $this->close($user->id, 'self'), fn () => $this->close($user->id, 'admin')]);
            self::assertSame(1, DB::table('account_closures')->where('user_id', $user->id)->count());
            self::assertSame(-12500, (int) DB::table('point_ledger_entries')->where('user_id', $user->id)->where('entry_type', 'forfeit')->sum('amount_delta'));
            self::assertSame(['paid' => 0, 'free' => 0], app(V2PointLedgerService::class)->rebuild($user->id));
        } finally {
            DB::purge(); DB::reconnect();
            Artisan::call('migrate:fresh', ['--path' => 'database/migrations-v2', '--force' => true]);
            \Carbon\Carbon::setTestNow(); \Carbon\CarbonImmutable::setTestNow();
        }
    }

    private function close(int $id, string $actor): array
    {
        return app(V2PointTransactionRunner::class)->run(function () use ($id, $actor): array {
            $user = User::query()->whereKey($id)->lockForUpdate()->firstOrFail();
            return app(V2UserClosureService::class)->closeLocked($user, $actor, 'normal', (string) Str::uuid7());
        });
    }

    private function race(array $workers): void
    {
        DB::disconnect();
        $children = []; $paths = []; $start = microtime(true) + 0.3;
        foreach ($workers as $worker) {
            $path = sys_get_temp_dir().'/closure-race-'.Str::uuid7().'.json'; $paths[] = $path;
            $pid = pcntl_fork(); self::assertNotSame(-1, $pid);
            if ($pid === 0) {
                DB::purge(); DB::reconnect();
                DB::statement("SET statement_timeout = '60s'");
                while (microtime(true) < $start) usleep(1000);
                try { $worker(); $result = 'PASS'; }
                catch (\Throwable $error) { $result = $error::class.':'.$error->getMessage(); }
                file_put_contents($path, json_encode($result)); DB::disconnect(); exit(0);
            }
            $children[] = $pid;
        }
        foreach ($children as $pid) {
            pcntl_waitpid($pid, $status);
            self::assertTrue(pcntl_wifexited($status)); self::assertSame(0, pcntl_wexitstatus($status));
        }
        DB::reconnect();
        foreach ($paths as $path) {
            $result = json_decode(file_get_contents($path), true); unlink($path);
            self::assertSame('PASS', $result);
        }
    }
}
