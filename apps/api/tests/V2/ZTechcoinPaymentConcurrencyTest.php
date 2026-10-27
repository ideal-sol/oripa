<?php

namespace Tests\V2;

use App\Domain\Payment\V2\Services\V2TechcoinKickbackService;
use App\Domain\Payment\V2\Services\V2TechcoinPaymentService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\V2\Fixtures\TechcoinScenario;
use Tests\TestCase;

final class ZTechcoinPaymentConcurrencyTest extends TestCase
{
    use TechcoinScenario;

    public function test_actual_process_races_never_duplicate_grants_or_reuse_provider_reference(): void
    {
        self::assertTrue(app()->environment('testing'));
        self::assertTrue(function_exists('pcntl_fork'), 'pcntl is required for financial concurrency verification.');
        $this->configureTechcoin(); Mail::fake();
        try {
            $user = $this->techcoinUser(); $plan = $this->techcoinPlan();
            $this->parallel([
                fn () => app(V2TechcoinPaymentService::class)->start($user, $plan->public_id, 'same-create-key'),
                fn () => app(V2TechcoinPaymentService::class)->start($user, $plan->public_id, 'same-create-key'),
            ]);
            self::assertSame(1, DB::table('payments')->where('user_id', $user->id)->count());
            foreach (['same-cookie', 'success-failure', 'same-reference'] as $scenario) {
                [, $first, $a] = $this->techcoinPayment();
                $b = $a; $paymentIds = [$first->id];
                if ($scenario === 'same-reference') {
                    [, $second, $b] = $this->techcoinPayment(); $paymentIds[] = $second->id;
                    $a['order_cpt'] = $b['order_cpt'] = 'race-'.Str::uuid();
                } elseif ($scenario === 'success-failure') {
                    $b['rel'] = '0';
                }
                $this->parallel([fn () => app(V2TechcoinKickbackService::class)->process($a), fn () => app(V2TechcoinKickbackService::class)->process($b)]);
                $grants = DB::table('payment_point_grants')->whereIn('payment_id', $paymentIds)->count();
                if ($scenario === 'success-failure') {
                    $state = DB::table('payments')->where('id', $first->id)->value('status');
                    self::assertContains($state, ['succeeded', 'failed']);
                    self::assertSame($state === 'succeeded' ? 1 : 0, $grants);
                } else self::assertSame(1, $grants, $scenario);
                self::assertSame($grants, DB::table('payment_receipt_snapshots')->whereIn('payment_id', $paymentIds)->count());
                self::assertSame($grants, DB::table('mail_deliveries')->whereIn('source_public_id', DB::table('payments')->whereIn('id', $paymentIds)->pluck('public_id'))->count());
            }
        } finally {
            DB::purge(); DB::reconnect();
            // Match the existing committed-race harness: restore only the disposable V2 test database.
            Artisan::call('migrate:fresh', ['--path' => 'database/migrations-v2', '--force' => true]);
        }
    }

    private function parallel(array $workers): void
    {
        $start = microtime(true) + 0.3; $children = []; $paths = [];
        DB::disconnect();
        foreach ($workers as $worker) {
            $path = sys_get_temp_dir().'/techcoin-race-'.Str::uuid().'.json'; $paths[] = $path;
            $pid = pcntl_fork(); self::assertNotSame(-1, $pid);
            if ($pid === 0) {
                DB::purge(); DB::reconnect();
                DB::statement("SET statement_timeout = '30s'");
                while (microtime(true) < $start) usleep(1000);
                try { $worker(); $outcome = 'PASS'; } catch (\Throwable $e) { $outcome = $e::class.':'.$e->getCode(); }
                file_put_contents($path, json_encode($outcome)); DB::disconnect(); exit(0);
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
