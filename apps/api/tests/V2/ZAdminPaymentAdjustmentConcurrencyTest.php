<?php

namespace Tests\V2;

use App\Domain\Payment\V2\Exceptions\V2AdminPaymentReadException;
use App\Domain\Payment\V2\Services\V2AdminPaymentAdjustmentService;
use App\Domain\Payment\V2\Services\V2PaymentService;
use App\Domain\Point\Exceptions\V2PointException;
use App\Domain\Point\Services\V2PointService;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\MemberRankAccountingFixture;
use Tests\TestCase;

final class ZAdminPaymentAdjustmentConcurrencyTest extends TestCase
{
    use MemberRankAccountingFixture;

    protected function setUp(): void
    {
        parent::setUp();
        if (getenv('V2_M9_CONCURRENCY_TEST') !== '1') self::markTestSkipped('Requires dedicated disposable M9 concurrency database.');
        self::assertTrue(app()->environment('testing'));
        self::assertSame('oripa_v2_m9_synthetic', DB::connection()->getDatabaseName());
        $this->configureRankFixture();
        self::assertFalse((bool) DB::table('member_rank_settings')->where('id', 1)->value('enabled'));
        Http::preventStrayRequests();
        Http::fake();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public static function races(): array
    {
        return [['same-key'], ['different-keys'], ['different-admins'], ['refund-cb'], ['complete-cancel'], ['cb-consume'], ['notification-cb'], ['reversal']];
    }

    public static function retryModes(): array
    {
        return [['deadlock'], ['serializable']];
    }

    #[DataProvider('retryModes')]
    public function test_real_postgres_retry_commits_each_adjustment_once(string $mode): void
    {
        $users = [$this->rankUser(), $this->rankUser()];
        $payments = array_map(fn ($user) => $this->settleRankPayment($user, 1000, 100), $users);
        $contexts = [$this->rankAdmin(), $this->rankAdmin()];
        $marker = tempnam(sys_get_temp_dir(), 'm9-retry-');
        $children = [];
        DB::disconnect();
        foreach ([0, 1] as $index) {
            $processId = pcntl_fork();
            self::assertGreaterThanOrEqual(0, $processId);
            if ($processId === 0) {
                DB::reconnect();
                $attempts = 0;
                $key = (string) Str::uuid7();
                try {
                    app(\App\Domain\Point\Services\V2PointTransactionRunner::class)->run(function () use ($mode, $users, $payments, $contexts, $index, $marker, $key, &$attempts): void {
                        $attempts++;
                        if ($mode === 'serializable') {
                            DB::statement('SET TRANSACTION ISOLATION LEVEL SERIALIZABLE');
                            DB::table('wallets')->whereIn('user_id', array_map(fn ($user) => $user->id, $users))->sum('paid_balance');
                        } else {
                            DB::table('users')->where('id', $users[$index]->id)->lockForUpdate()->firstOrFail();
                        }
                        if ($attempts === 1) {
                            file_put_contents($marker.'.ready'.$index, '1');
                            $deadline = microtime(true) + 20;
                            while (! file_exists($marker.'.ready'.(1 - $index))) {
                                if (microtime(true) > $deadline) throw new \RuntimeException('Retry barrier timeout');
                                usleep(1000);
                            }
                        }
                        if ($mode === 'deadlock') DB::table('users')->where('id', $users[1 - $index]->id)->lockForUpdate()->firstOrFail();
                        app(V2AdminPaymentAdjustmentService::class)->write($contexts[$index], $payments[$index]->public_id, 'chargeback', ['reason' => '実DB再試行'], $key);
                    });
                    $result = ['status' => 'success', 'attempts' => $attempts];
                } catch (\Throwable $exception) {
                    $result = ['status' => get_class($exception).':'.$exception->getMessage(), 'attempts' => $attempts];
                }
                file_put_contents($marker.'.result'.$index, json_encode($result, JSON_THROW_ON_ERROR));
                DB::disconnect();
                exit(0);
            }
            $children[] = $processId;
        }
        $attempts = 0;
        foreach ($children as $index => $child) {
            pcntl_waitpid($child, $status);
            self::assertTrue(pcntl_wifexited($status));
            self::assertSame(0, pcntl_wexitstatus($status));
            $result = json_decode(file_get_contents($marker.'.result'.$index), true, flags: JSON_THROW_ON_ERROR);
            self::assertSame('success', $result['status']);
            $attempts += $result['attempts'];
            unlink($marker.'.result'.$index);
            unlink($marker.'.ready'.$index);
        }
        unlink($marker);
        self::assertGreaterThanOrEqual(3, $attempts, 'The real PostgreSQL conflict must cause a retry.');
        DB::reconnect();
        foreach ($payments as $payment) {
            self::assertSame(1, DB::table('payment_adjustments')->where('payment_id', $payment->id)->where('status', 'succeeded')->count());
            self::assertDatabaseHas('wallets', ['user_id' => $payment->user_id, 'paid_balance' => 0, 'free_balance' => 0]);
        }
    }

    #[DataProvider('races')]
    public function test_actual_postgres_concurrent_requests_preserve_single_adjustment(string $race): void
    {
        $user = $this->rankUser();
        [$payment, $event] = $this->rankPayment($user, 1000, 100);
        app(V2PaymentService::class)->confirmSucceeded($event->id);
        $contexts = [$this->rankAdmin(), $this->rankAdmin()];
        $keys = [(string) Str::uuid7(), (string) Str::uuid7()];
        $service = app(V2AdminPaymentAdjustmentService::class);
        $parent = null;
        if (in_array($race, ['complete-cancel', 'reversal'], true)) {
            $parent = $service->write($contexts[0], $payment->public_id, $race === 'reversal' ? 'chargeback' : 'refund', ['reason' => '準備'], (string) Str::uuid7())['data']['id'];
        }
        $marker = tempnam(sys_get_temp_dir(), 'm9-race-');
        $children = [];
        $barriers = [];
        DB::disconnect();
        foreach ([0, 1] as $index) {
            $barrier = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
            self::assertIsArray($barrier);
            $processId = pcntl_fork();
            self::assertGreaterThanOrEqual(0, $processId);
            if ($processId === 0) {
                fclose($barrier[0]);
                fread($barrier[1], 1);
                fclose($barrier[1]);
                DB::reconnect();
                DB::statement("SET application_name = 'm9-race-child'");
                DB::statement("SET lock_timeout = '20s'");
                try {
                    if ($race === 'cb-consume' && $index === 1) {
                        app(V2PointService::class)->consume($user->id, 500, 'm9-consume:'.Str::uuid7());
                        $result = ['status' => 'spent'];
                    } elseif ($race === 'notification-cb' && $index === 1) {
                        app(V2PaymentService::class)->confirmSucceeded($event->id);
                        $result = ['status' => 'notified'];
                    } else {
                        $operation = match ($race) {
                            'refund-cb' => $index === 0 ? 'refund' : 'chargeback',
                            'complete-cancel' => $index === 0 ? 'complete' : 'cancel',
                            'cb-consume', 'notification-cb' => 'chargeback',
                            'reversal' => 'reversal',
                            default => 'refund',
                        };
                        $input = ['reason' => '並列検証'];
                        if ($operation === 'complete') $input['provider_refund_confirmed'] = true;
                        $result = app(V2AdminPaymentAdjustmentService::class)->write($contexts[$race === 'different-admins' ? $index : 0], $payment->public_id, $operation, $input, $keys[$race === 'same-key' ? 0 : $index], $parent);
                        $result = ['status' => 'success', 'id' => $result['data']['id']];
                    }
                    Http::assertNothingSent();
                } catch (V2AdminPaymentReadException $exception) {
                    $result = ['status' => 'conflict', 'code' => $exception->errorCode, 'http' => $exception->status];
                } catch (V2PointException $exception) {
                    $result = ['status' => 'point-conflict', 'code' => $exception->getMessage()];
                } catch (\Throwable $exception) {
                    $result = ['status' => 'unexpected', 'error' => get_class($exception).':'.$exception->getMessage()];
                }
                file_put_contents($marker.'.'.$index, json_encode($result, JSON_THROW_ON_ERROR));
                DB::disconnect();
                exit(0);
            }
            fclose($barrier[1]);
            $barriers[] = $barrier[0];
            $children[] = $processId;
        }
        DB::reconnect();
        DB::beginTransaction();
        DB::table('users')->where('id', $user->id)->lockForUpdate()->firstOrFail();
        foreach ($barriers as $barrier) { fwrite($barrier, '1'); fclose($barrier); }
        $blocked = false;
        for ($attempt = 0; $attempt < 200; $attempt++) {
            DB::select('SELECT pg_stat_clear_snapshot()');
            $blocked = (bool) DB::selectOne("SELECT EXISTS (SELECT 1 FROM pg_stat_activity WHERE application_name = 'm9-race-child' AND wait_event_type = 'Lock') AS blocked")->blocked;
            if ($blocked) break;
            usleep(50000);
        }
        DB::commit();
        foreach ($children as $child) {
            pcntl_waitpid($child, $status);
            self::assertTrue(pcntl_wifexited($status));
            self::assertSame(0, pcntl_wexitstatus($status));
        }
        self::assertTrue($blocked, 'Observe an actual PostgreSQL lock wait, not sequential mock calls.');
        $results = [];
        foreach ([0, 1] as $index) {
            $results[] = json_decode(file_get_contents($marker.'.'.$index), true, flags: JSON_THROW_ON_ERROR);
            unlink($marker.'.'.$index);
        }
        unlink($marker);
        foreach ($results as $result) {
            self::assertNotSame('unexpected', $result['status'], json_encode($results));
            if ($result['status'] === 'conflict') self::assertSame(409, $result['http']);
        }
        $statuses = array_column($results, 'status');
        if ($race === 'same-key') {
            self::assertSame(['success', 'success'], $statuses);
            self::assertSame($results[0]['id'], $results[1]['id']);
        } elseif ($race === 'notification-cb') {
            self::assertSame(['success', 'notified'], $statuses);
        } elseif ($race === 'cb-consume') {
            self::assertSame('success', $statuses[0]);
            self::assertContains($statuses[1], ['spent', 'point-conflict']);
        } else {
            sort($statuses);
            self::assertSame(['conflict', 'success'], $statuses);
        }
        self::assertSame($race === 'reversal' ? 2 : 1, DB::table('payment_adjustments')->where('payment_id', $payment->id)->count());
        self::assertSame(1, DB::table('payment_point_grants')->where('payment_id', $payment->id)->count());
        $wallet = DB::table('wallets')->where('user_id', $user->id)->sole();
        foreach (['paid', 'free'] as $type) {
            $lots = DB::table('point_lots')->where('user_id', $user->id)->where('point_type', $type);
            self::assertGreaterThanOrEqual(0, (int) $wallet->{$type.'_balance'});
            self::assertSame((int) $wallet->{$type.'_balance'}, (int) $lots->sum('remaining_amount'));
            self::assertSame((int) $wallet->{$type.'_reserved_balance'}, (int) $lots->sum('reserved_amount'));
            self::assertSame((int) $wallet->{$type.'_balance'}, (int) DB::table('point_ledger_entries')->where('user_id', $user->id)->where('point_type', $type)->sum('amount_delta'));
        }
    }
}
