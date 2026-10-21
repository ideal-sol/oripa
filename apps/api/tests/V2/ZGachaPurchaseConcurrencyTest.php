<?php

namespace Tests\V2;

use App\Domain\Draw\Exceptions\V2DrawException;
use App\Domain\Payment\V2\Services\V2PaymentService;
use App\Domain\Payment\V2\Services\V2AdminPaymentAdjustmentService;
use App\Models\V2\User;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\MemberRankGachaFixture;
use Tests\TestCase;

final class ZGachaPurchaseConcurrencyTest extends TestCase
{
    use MemberRankGachaFixture;

    public function test_payment_refund_and_concurrent_draws_observe_committed_purchase_history(): void
    {
        if (getenv('V2_G1_CONCURRENCY_TEST') !== '1') {
            self::markTestSkipped('Requires the explicit isolated G1 concurrency database.');
        }
        self::assertTrue(app()->environment('testing'));
        self::assertSame('oripa_v2_g1_synthetic', DB::connection()->getDatabaseName());
        self::assertSame(0, DB::table('catalog_gachas')->count());
        self::assertTrue(function_exists('pcntl_fork'));
        $this->configureRankFixture();
        $this->importRankGachas();
        $user = $this->fundedRankUser(1);
        $gacha = $this->rankGacha(overrides: ['purchase_count_mode' => 'gte', 'purchase_count_value' => 1,
            'purchase_amount_mode' => 'gte', 'purchase_amount_value' => 1000]);
        [$payment, $event] = $this->rankPayment($user, 1000);
        $paid = $this->race($user, $gacha, fn () => app(V2PaymentService::class)->confirmSucceeded($event->id));
        self::assertArrayHasKey('draw', $paid);
        $snapshot = DB::table('draw_requests')->where('public_id', $paid['draw']['id'])->sole();
        self::assertSame(1, (int) $snapshot->purchase_count_snapshot);
        self::assertSame(1000, (int) $snapshot->purchase_amount_snapshot);
        $parallel = $this->race($user, $gacha, fn () => $this->rankDraw($user, $gacha), true);
        self::assertArrayHasKey('draw', $parallel);
        self::assertSame(3, DB::table('draw_requests')->where('user_id', $user->id)->count());
        $admin = $this->rankAdmin();
        $adjustments = app(V2AdminPaymentAdjustmentService::class);
        $prepared = $adjustments->write($admin, $payment->public_id, 'refund', ['reason' => 'Synthetic G1 race'], (string) Str::uuid7());
        $refunded = $this->race($user, $gacha, fn () => $adjustments->write($admin, $payment->public_id, 'complete',
            ['reason' => 'Synthetic G1 race completion', 'provider_refund_confirmed' => true], (string) Str::uuid7(), $prepared['data']['id']));
        self::assertSame('GACHA_PURCHASE_COUNT_NOT_ELIGIBLE', $refunded['code']);
        self::assertSame(3, DB::table('draw_requests')->where('user_id', $user->id)->count());
        self::assertSame(1000, (int) DB::table('draw_requests')->where('id', $snapshot->id)->value('purchase_amount_snapshot'));
        Carbon::setTestNow();
        CarbonImmutable::setTestNow();
    }

    private function race(User $user, object $gacha, callable $parentWrite, bool $lockGacha = false): array
    {
        $path = tempnam(sys_get_temp_dir(), 'g1-race-');
        [$parentSocket, $childSocket] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        DB::disconnect();
        $processId = pcntl_fork();
        self::assertNotSame(-1, $processId);
        if ($processId === 0) {
            fclose($parentSocket);
            fread($childSocket, 1);
            DB::reconnect();
            fwrite($childSocket, DB::selectOne('SELECT pg_backend_pid() AS pid')->pid."\n");
            try {
                DB::statement("SET lock_timeout = '30s'");
                $result = ['draw' => $this->rankDraw(User::query()->findOrFail($user->id), $gacha)];
            } catch (V2DrawException $exception) {
                $result = ['code' => $exception->errorCode];
            } catch (\Throwable $exception) {
                $result = ['error' => $exception->getMessage()];
            }
            file_put_contents($path, json_encode($result, JSON_THROW_ON_ERROR));
            DB::disconnect();
            fclose($childSocket);
            exit(0);
        }
        fclose($childSocket);
        DB::reconnect();
        try {
            DB::transaction(function () use ($user, $gacha, $parentSocket, $parentWrite, $lockGacha): void {
                DB::table($lockGacha ? 'catalog_gachas' : 'users')
                    ->where('id', $lockGacha ? $gacha->id : $user->id)->lockForUpdate()->firstOrFail();
                fwrite($parentSocket, '1');
                $backend = (int) fgets($parentSocket);
                $waiting = false;
                for ($attempt = 0; $attempt < 200; $attempt++) {
                    $waiting = DB::selectOne('SELECT wait_event_type FROM pg_stat_activity WHERE pid = ?', [$backend])?->wait_event_type === 'Lock';
                    if ($waiting) {
                        break;
                    }
                    usleep(50000);
                }
                self::assertTrue($waiting, 'Competing Draw must wait on the serialization lock.');
                $parentWrite();
            });
        } finally {
            pcntl_waitpid($processId, $status);
            fclose($parentSocket);
        }
        $outcome = json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
        unlink($path);
        self::assertTrue(pcntl_wifexited($status));
        self::assertSame(0, pcntl_wexitstatus($status));
        self::assertArrayNotHasKey('error', $outcome, json_encode($outcome));

        return $outcome;
    }
}
