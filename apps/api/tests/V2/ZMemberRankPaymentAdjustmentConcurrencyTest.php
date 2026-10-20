<?php

namespace Tests\V2;

use App\Domain\MemberRank\Services\V2MemberRankAdminService;
use App\Domain\MemberRank\Services\V2MemberRankResolver;
use App\Domain\Payment\V2\Services\V2AdminPaymentAdjustmentService;
use App\Domain\Point\Services\V2PointService;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\MemberRankGachaFixture;
use Tests\TestCase;

final class ZMemberRankPaymentAdjustmentConcurrencyTest extends TestCase
{
    use MemberRankGachaFixture;

    protected function setUp(): void
    {
        parent::setUp();
        if (getenv('V2_M8_CONCURRENCY_TEST') !== '1') {
            self::markTestSkipped('Requires dedicated disposable M8 concurrency database.');
        }
        self::assertTrue(app()->environment('testing'));
        self::assertSame('oripa_v2_m8_synthetic', DB::connection()->getDatabaseName());
        $this->configureRankFixture();
        $this->enableRankFixture();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public static function races(): array
    {
        return [['chargeback'], ['refund'], ['consume'], ['manual'], ['monthly'], ['expire'], ['draw']];
    }

    public static function retryModes(): array
    {
        return [['deadlock'], ['serializable']];
    }

    #[DataProvider('retryModes')]
    public function test_outer_retry_handles_actual_postgres_conflict_without_duplicate_rank_entries(string $mode): void
    {
        $users = [$this->rankUser(), $this->rankUser()];
        $payments = array_map(fn ($user) => $this->settleRankPayment($user, 4000, 150), $users);
        $contexts = [$this->rankAdmin(), $this->rankAdmin()];
        $marker = tempnam(sys_get_temp_dir(), 'm8-retry-');
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
                            $deadline = microtime(true) + 60;
                            while (! file_exists($marker.'.ready'.(1 - $index))) {
                                if (microtime(true) > $deadline) {
                                    throw new \RuntimeException('Retry barrier timeout');
                                }
                                usleep(1000);
                            }
                        }
                        if ($mode === 'deadlock') {
                            DB::table('users')->where('id', $users[1 - $index]->id)->lockForUpdate()->firstOrFail();
                        }
                        app(V2AdminPaymentAdjustmentService::class)->write($contexts[$index], $payments[$index]->public_id,
                            'chargeback', ['reason' => '実DB再試行'], $key);
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
            self::assertLessThanOrEqual(3, $result['attempts']);
            $attempts += $result['attempts'];
            unlink($marker.'.result'.$index);
            unlink($marker.'.ready'.$index);
        }
        unlink($marker);
        self::assertGreaterThanOrEqual(3, $attempts);
        DB::reconnect();
        foreach ($payments as $payment) {
            self::assertSame(1, DB::table('member_rank_point_entries')->where('origin_payment_id', $payment->id)->count());
            self::assertDatabaseHas('member_rank_month_totals', ['user_id' => $payment->user_id, 'points' => 0]);
        }
    }

    #[DataProvider('races')]
    public function test_user_serialization_preserves_rank_and_point_totals(string $race): void
    {
        $gacha = null;
        if ($race === 'draw') {
            $this->importRankGachas();
            $gacha = $this->rankGacha();
        }
        $user = $this->rankUser($race === 'draw');
        $this->seedRankState($user, 2);
        $payment = $this->settleRankPayment($user, 4000, 150);
        if ($race === 'expire') {
            $this->rankClock(CarbonImmutable::parse(DB::table('point_lots')->where('user_id', $user->id)->min('expire_at'))->toIso8601String());
        } elseif ($race === 'monthly') {
            $this->rankClock('2026-10-31T15:00:00Z');
        }
        $context = $this->rankAdmin();
        $input = ['delta' => -1, 'reason' => '並列修正'];
        $preview = $race === 'manual'
            ? app(V2MemberRankAdminService::class)->preview($context, $user->public_id, 'points', $input)
            : null;
        $marker = tempnam(sys_get_temp_dir(), 'm8-race-');
        $children = [];
        DB::disconnect();
        foreach ([0, 1] as $index) {
            $processId = pcntl_fork();
            self::assertGreaterThanOrEqual(0, $processId);
            if ($processId === 0) {
                DB::reconnect();
                file_put_contents($marker.'.ready'.$index, '1');
                $deadline = microtime(true) + 60;
                while (! file_exists($marker.'.ready'.(1 - $index))) {
                    if (microtime(true) > $deadline) {
                        exit(2);
                    }
                    usleep(1000);
                }
                try {
                    if ($index === 0 || $race === 'chargeback') {
                        app(V2AdminPaymentAdjustmentService::class)->write($context, $payment->public_id, 'chargeback', ['reason' => '並列CB'], (string) Str::uuid7());
                    } elseif ($race === 'refund') {
                        app(V2AdminPaymentAdjustmentService::class)->write($context, $payment->public_id, 'refund', ['reason' => '並列返金'], (string) Str::uuid7());
                    } elseif ($race === 'consume') {
                        app(V2PointService::class)->consume($user->id, 100, 'm8-race:'.Str::uuid7());
                    } elseif ($race === 'draw') {
                        $this->rankDraw($user, $gacha);
                    } elseif ($race === 'manual') {
                        app(V2MemberRankAdminService::class)->write($context, $user->public_id, 'points',
                            [...$input, 'expected_revision' => $preview['expected_revision'], 'preview_token' => $preview['preview_token']], (string) Str::uuid7());
                    } elseif ($race === 'monthly') {
                        DB::transaction(fn () => app(V2MemberRankResolver::class)->materialize($user->id));
                    } else {
                        app(V2PointService::class)->expire(CarbonImmutable::now());
                    }
                    $result = 'success';
                } catch (\App\Domain\Payment\V2\Exceptions\V2AdminPaymentReadException|\App\Domain\MemberRank\Exceptions\V2MemberRankException $exception) {
                    $result = $exception->status === 409 ? 'conflict' : $exception->getMessage();
                } catch (\App\Domain\Point\Exceptions\V2PointException $exception) {
                    $result = $exception->getMessage() === 'INSUFFICIENT_POINT_BALANCE' ? 'conflict' : $exception->getMessage();
                } catch (\App\Domain\Draw\Exceptions\V2DrawException $exception) {
                    $result = $exception->errorCode === 'INSUFFICIENT_POINTS' ? 'conflict' : $exception->errorCode;
                } catch (\Throwable $exception) {
                    $result = get_class($exception).':'.$exception->getMessage();
                }
                file_put_contents($marker.'.result'.$index, $result);
                DB::disconnect();
                exit(0);
            }
            $children[] = $processId;
        }
        $successes = 0;
        foreach ($children as $index => $child) {
            pcntl_waitpid($child, $status);
            self::assertTrue(pcntl_wifexited($status));
            self::assertSame(0, pcntl_wexitstatus($status));
            $result = file_get_contents($marker.'.result'.$index);
            self::assertContains($result, ['success', 'conflict']);
            $successes += $result === 'success' ? 1 : 0;
            unlink($marker.'.result'.$index);
            unlink($marker.'.ready'.$index);
        }
        unlink($marker);
        DB::reconnect();
        self::assertGreaterThan(0, $successes);
        if (in_array($race, ['refund', 'chargeback'], true)) {
            self::assertSame(1, $successes);
        }
        self::assertSame((int) DB::table('member_rank_point_entries')->where('user_id', $user->id)->sum('applied_delta'),
            (int) DB::table('member_rank_month_totals')->where('user_id', $user->id)->sum('points'));
        self::assertLessThanOrEqual(1, DB::table('member_rank_point_entries')->where('origin_payment_id', $payment->id)->count());
        $wallet = DB::table('wallets')->where('user_id', $user->id)->sole();
        foreach (['paid', 'free'] as $type) {
            self::assertSame((int) $wallet->{$type.'_balance'},
                (int) DB::table('point_lots')->where('user_id', $user->id)->where('point_type', $type)->sum('remaining_amount'));
            self::assertSame((int) $wallet->{$type.'_reserved_balance'},
                (int) DB::table('point_lots')->where('user_id', $user->id)->where('point_type', $type)->sum('reserved_amount'));
            self::assertGreaterThanOrEqual(0, (int) $wallet->{$type.'_balance'});
        }
    }
}
