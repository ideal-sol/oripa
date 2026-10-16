<?php

namespace Tests\V2;

use App\Domain\Payment\V2\Services\V2PaymentService;
use App\Domain\Identity\Services\V2PreRegistrationService;
use App\Domain\MemberRank\Services\V2MemberRankAccountingService;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Tests\Support\MemberRankAccountingFixture;
use Tests\TestCase;

final class ZMemberRankAccountingConcurrencyTest extends TestCase
{
    use MemberRankAccountingFixture;

    public function test_distinct_payments_and_simultaneous_duplicate_events_commit_exactly_once(): void
    {
        if (getenv('V2_RANK_POINTS_CONCURRENCY_TEST') !== '1') {
            self::markTestSkipped('Requires the dedicated disposable rank points concurrency database.');
        }
        self::assertTrue(app()->environment('testing'));
        self::assertSame('oripa_v2_rankpoints_synthetic', DB::connection()->getDatabaseName());
        self::assertTrue(function_exists('pcntl_fork'));
        $this->configureRankFixture();
        $this->enableRankFixture();
        DB::table('pre_registration_settings')->where('id', 1)->update(['is_enabled' => true, 'cutoff_at' => '2026-10-31T14:00:00Z']);
        $user = $this->rankUser(true);
        $activation = app(V2PreRegistrationService::class)->activate($this->rankAdmin(), (string) \Illuminate\Support\Str::uuid7(), ['expected_revision' => 1]);
        self::assertSame(1, $activation['data']['summary']['activated']);
        self::assertSame(0, DB::table('member_rank_point_entries')->where('user_id', $user->id)->count());
        [$firstPayment, $firstEvent] = $this->rankPayment($user, 5000);
        [$secondPayment, $secondEvent] = $this->rankPayment($user, 5000, planId: $firstPayment->point_purchase_plan_id);
        $processes = [];
        $startAt = microtime(true) + 0.5;
        DB::disconnect();
        foreach ([$firstEvent->id, $secondEvent->id, $firstEvent->id, $secondEvent->id] as $eventId) {
            $path = tempnam(sys_get_temp_dir(), 'rank-points-race-');
            $processId = pcntl_fork();
            self::assertNotSame(-1, $processId);
            if ($processId === 0) {
                while (microtime(true) < $startAt) {
                    usleep(1000);
                }
                DB::reconnect();
                DB::statement("SET lock_timeout = '10s'");
                try {
                    $grant = app(V2PaymentService::class)->confirmSucceeded($eventId);
                    DB::transaction(fn () => app(V2MemberRankAccountingService::class)->accountGrantedPayment($grant->payment_id));
                    $outcome = 'success';
                } catch (\Throwable $exception) {
                    $outcome = get_class($exception).':'.$exception->getCode();
                }
                file_put_contents($path, $outcome);
                DB::disconnect();
                exit(0);
            }
            $processes[] = [$processId, $path];
        }
        foreach ($processes as [$processId, $path]) {
            pcntl_waitpid($processId, $status);
            self::assertTrue(pcntl_wifexited($status));
            self::assertSame(0, pcntl_wexitstatus($status));
            self::assertSame('success', file_get_contents($path));
            unlink($path);
        }
        DB::reconnect();
        self::assertDatabaseHas('member_rank_month_totals', ['user_id' => $user->id, 'month' => '2026-10-01', 'points' => 10000]);
        self::assertDatabaseHas('member_rank_states', ['user_id' => $user->id, 'level' => 2]);
        self::assertDatabaseHas('wallets', ['user_id' => $user->id, 'paid_balance' => 10000, 'free_balance' => 1000]);
        self::assertSame(2, DB::table('member_rank_point_entries')->where('user_id', $user->id)->count());
        self::assertSame(1, DB::table('member_rank_changes')->where('user_id', $user->id)->count());
        self::assertSame(1, DB::table('member_rank_achievements')->where('user_id', $user->id)->count());
        self::assertSame(2, DB::table('payment_point_grants')->whereIn('payment_id', [$firstPayment->id, $secondPayment->id])->count());
        self::assertSame(1, DB::table('point_operations')->where('user_id', $user->id)->where('source_type', 'member_rank_promotion')->count());
        Carbon::setTestNow();
        CarbonImmutable::setTestNow();
    }
}
