<?php

namespace Tests\V2;

use App\Domain\MemberRank\Exceptions\V2MemberRankException;
use App\Domain\MemberRank\Services\V2MemberRankAdminService;
use App\Domain\MemberRank\Services\V2MemberRankMonthlyWorker;
use App\Domain\Payment\V2\Services\V2PaymentService;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\MemberRankAccountingFixture;
use Tests\TestCase;

final class ZMemberRankAdminConcurrencyTest extends TestCase
{
    use MemberRankAccountingFixture;

    public function test_decrement_duplicate_payment_worker_and_projection_are_serialized_without_lost_updates(): void
    {
        if (getenv('V2_M7_CONCURRENCY_TEST') !== '1') {
            self::markTestSkipped('Requires the dedicated disposable M7 concurrency database.');
        }
        self::assertTrue(app()->environment('testing'));
        self::assertSame('oripa_v2_m7_synthetic', DB::connection()->getDatabaseName());
        $this->configureRankFixture();
        $this->enableRankFixture();
        $admin = $this->rankAdmin();
        $user = $this->rankUser();
        $other = $this->rankUser();
        $this->seedRankState($other, 7);
        $this->rankClock('2026-10-31T15:00:00Z');
        $admin = $this->rankAdmin();
        $service = app(V2MemberRankAdminService::class);
        $input = ['delta' => 10000, 'reason' => 'concurrency seed'];
        $preview = $service->preview($admin, $user->public_id, 'points', $input);
        $service->write($admin, $user->public_id, 'points', [...$input, 'expected_revision' => $preview['expected_revision'], 'preview_token' => $preview['preview_token']], (string) Str::uuid7());
        [$payment, $event] = $this->rankPayment($user, 5000);
        $input = ['delta' => -10000, 'reason' => 'concurrent correction'];
        $preview = $service->preview($admin, $user->public_id, 'points', $input);
        $payload = [...$input, 'expected_revision' => $preview['expected_revision'], 'preview_token' => $preview['preview_token']];
        $key = (string) Str::uuid7();
        $processes = [];
        DB::disconnect();
        foreach (['manual', 'manual', 'payment', 'worker', 'read'] as $action) {
            $path = tempnam(sys_get_temp_dir(), 'm7-concurrency-');
            $barrier = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
            self::assertIsArray($barrier);
            $processId = pcntl_fork();
            self::assertGreaterThanOrEqual(0, $processId);
            if ($processId === 0) {
                fclose($barrier[0]);
                fread($barrier[1], 1);
                fclose($barrier[1]);
                DB::reconnect();
                DB::statement("SET lock_timeout = '10s'");
                try {
                    if ($action === 'manual') {
                        $service->write($admin, $user->public_id, 'points', $payload, $key);
                    } elseif ($action === 'payment') {
                        app(V2PaymentService::class)->confirmSucceeded($event->id);
                    } elseif ($action === 'worker') {
                        app(V2MemberRankMonthlyWorker::class)->run('m7-concurrent', 100);
                    } else {
                        app(\App\Domain\MemberRank\Services\V2MemberRankResolver::class)->project($user->id);
                    }
                    $outcome = 'success';
                } catch (V2MemberRankException $exception) {
                    $outcome = $exception->errorCode;
                } catch (\Throwable $exception) {
                    $outcome = get_class($exception).':'.$exception->getCode();
                }
                file_put_contents($path, $outcome);
                DB::disconnect();
                exit(0);
            }
            fclose($barrier[1]);
            $processes[] = [$processId, $path, $action, $barrier[0]];
        }
        foreach ($processes as [, , , $barrier]) {
            fwrite($barrier, '1');
            fclose($barrier);
        }
        foreach ($processes as [$processId, $path, $action]) {
            pcntl_waitpid($processId, $status);
            self::assertTrue(pcntl_wifexited($status));
            self::assertSame(0, pcntl_wexitstatus($status));
            $outcome = file_get_contents($path);
            self::assertContains($outcome, $action === 'manual' ? ['success', 'MEMBER_RANK_REVISION_CONFLICT'] : ['success']);
            unlink($path);
        }
        DB::reconnect();
        if (! DB::table('member_rank_point_entries')->where('user_id', $user->id)->where('applied_delta', -10000)->exists()) {
            $preview = $service->preview($admin, $user->public_id, 'points', $input);
            $service->write($admin, $user->public_id, 'points', [...$input, 'expected_revision' => $preview['expected_revision'], 'preview_token' => $preview['preview_token']], $key);
        }
        self::assertDatabaseHas('member_rank_month_totals', ['user_id' => $user->id, 'month' => '2026-11-01', 'points' => 5000]);
        self::assertSame(1, DB::table('member_rank_point_entries')->where('user_id', $user->id)->where('applied_delta', -10000)->count());
        self::assertSame(1, DB::table('member_rank_bonus_recoveries')->where('user_id', $user->id)->count());
        self::assertDatabaseHas('wallets', ['user_id' => $user->id, 'paid_balance' => 5000, 'free_balance' => 0]);
        self::assertSame(5000, (int) DB::table('member_rank_point_entries')->where('user_id', $user->id)->sum('applied_delta'));
        self::assertDatabaseHas('member_rank_states', ['user_id' => $other->id, 'level' => 6, 'baseline_month' => '2026-11-01']);
        Carbon::setTestNow();
        CarbonImmutable::setTestNow();
    }
}
