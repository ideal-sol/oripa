<?php

namespace Tests\V2;

use App\Domain\Identity\Services\V2PreRegistrationService;
use App\Domain\MemberRank\Services\V2MemberRankMonthlyWorker;
use App\Domain\MemberRank\Services\V2MemberRankResolver;
use App\Domain\MemberRank\Services\V2MemberRankSettingsService;
use App\Domain\Payment\V2\Services\V2PaymentService;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\MemberRankAccountingFixture;
use Tests\TestCase;

final class MemberRankMonthlyTest extends TestCase
{
    use MemberRankAccountingFixture;

    protected function setUp(): void
    {
        parent::setUp();
        $this->configureRankFixture();
        DB::beginTransaction();
    }

    protected function tearDown(): void
    {
        while (DB::transactionLevel() > 0) {
            DB::rollBack();
        }
        Carbon::setTestNow();
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public static function thresholds(): array
    {
        return [[4, 69999, 3], [4, 70000, 4], [2, 4999, 1], [2, 5000, 2], [1, 0, 1]];
    }

    #[DataProvider('thresholds')]
    public function test_jst_boundary_projection_is_read_only_and_matches_materialization(int $level, int $points, int $expected): void
    {
        $this->enableRankFixture();
        $user = $this->rankUser();
        $this->seedRankState($user, $level);
        if ($points > 0) {
            $this->settleRankPayment($user, $points);
        }
        $resolver = app(V2MemberRankResolver::class);
        self::assertSame($level, $resolver->project($user->id)['level']);
        $before = $this->snapshot();
        $this->rankClock('2026-10-31T15:00:00Z');
        $projected = $resolver->project($user->id);
        self::assertSame($expected, $projected['level']);
        self::assertSame(0, $projected['rank_points']);
        self::assertSame('2026-11-01', $projected['last_judged_month']);
        self::assertSame($before, $this->snapshot());
        $this->rankClock('2026-10-31T15:00:01Z');
        self::assertSame($projected, $resolver->project($user->id));
        self::assertSame($projected, $resolver->materialize($user->id));
        $after = $this->snapshot();
        self::assertSame($expected, $resolver->materialize($user->id)['level']);
        self::assertSame($after, $this->snapshot());
        self::assertSame($points, (int) DB::table('member_rank_month_totals')->where('user_id', $user->id)->where('month', '2026-10-01')->value('points'));
        self::assertSame($expected < $level ? 1 : 0, DB::table('member_rank_changes')->where('user_id', $user->id)->where('reason', 'demotion')->count());
        if ($expected < $level) {
            self::assertDatabaseHas('member_rank_changes', [
                'user_id' => $user->id, 'reason' => 'demotion', 'month' => '2026-10-01',
                'judgment_month' => '2026-11-01', 'rank_points' => $points, 'setting_revision' => 1, 'point_entry_id' => null,
            ]);
        }
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
    }

    public static function skips(): array
    {
        return [[0, 3, 0], [1, 4, 0], [2, 4, 1]];
    }

    #[DataProvider('skips')]
    public function test_skip_consumption_and_six_month_catchup(int $skip, int $november, int $remaining): void
    {
        $this->enableRankFixture();
        $user = $this->rankUser();
        $this->seedRankState($user, 4);
        DB::table('member_rank_states')->where('user_id', $user->id)->update(['skip_count' => $skip]);
        $this->rankClock('2026-10-31T15:00:00Z');
        $resolver = app(V2MemberRankResolver::class);
        $result = $resolver->materialize($user->id);
        self::assertSame($november, $result['level']);
        self::assertSame($remaining, $result['skip_count']);
        $this->rankClock('2026-11-30T15:00:00Z');
        self::assertSame($remaining > 0 ? 4 : $november - 1, $resolver->materialize($user->id)['level']);
        $this->rankClock('2026-12-31T15:00:00Z');
        self::assertSame($skip === 2 ? 3 : max(1, $november - 2), $resolver->materialize($user->id)['level']);
        $vip = $this->rankUser();
        $this->seedRankState($vip, 7);
        $this->rankClock('2027-03-31T15:00:00Z');
        $projection = $resolver->project($vip->id);
        self::assertSame(1, $projection['level']);
        self::assertSame([6, 5, 4, 3, 2, 1], array_column($projection['judgments'], 'to_level'));
        self::assertSame($projection, $resolver->materialize($vip->id));
        self::assertSame(6, DB::table('member_rank_changes')->where('user_id', $vip->id)->count());
    }

    public function test_historical_settings_and_skip_are_independent_of_new_defaults(): void
    {
        $this->enableRankFixture();
        $user = $this->rankUser();
        $this->seedRankState($user, 4);
        $this->settleRankPayment($user, 70000);
        DB::table('member_rank_states')->where('user_id', $user->id)->update(['skip_count' => 1]);
        $settings = app(V2MemberRankSettingsService::class);
        $ranks = $settings->effectiveAt(CarbonImmutable::now())['ranks'];
        $ranks[3]['maintain_line'] = 90000;
        $ranks[3]['reach_line'] = 110000;
        $context = $this->rankAdmin();
        $settings->update($context, (string) Str::uuid7(), [
            'expected_revision' => 1, 'enabled' => true, 'default_skip_count' => 12, 'ranks' => $ranks,
        ]);
        $this->rankClock('2026-10-31T15:00:00Z');
        $resolver = app(V2MemberRankResolver::class);
        $first = $resolver->materialize($user->id);
        self::assertSame(4, $first['level']);
        self::assertSame(0, $first['skip_count']);
        self::assertSame(1, $first['judgments'][0]['setting_revision']);
        self::assertTrue($first['judgments'][0]['skipped']);
        $this->settleRankPayment($user, 80000);
        $this->rankClock('2026-11-30T15:00:00Z');
        $second = $resolver->materialize($user->id);
        self::assertSame(3, $second['level']);
        self::assertSame(2, $second['judgments'][0]['setting_revision']);
        self::assertSame(0, $second['skip_count']);
    }

    public static function orders(): array
    {
        return [['read'], ['worker'], ['payment']];
    }

    #[DataProvider('orders')]
    public function test_worker_read_and_payment_orders_converge_without_regrant(string $order): void
    {
        $this->enableRankFixture();
        $user = $this->rankUser();
        $this->settleRankPayment($user, 100000);
        [$payment, $event] = $this->rankPayment($user, 100000);
        $this->rankClock('2026-11-30T15:00:01Z');
        $resolver = app(V2MemberRankResolver::class);
        $worker = app(V2MemberRankMonthlyWorker::class);
        if ($order === 'read') {
            $before = $this->snapshot();
            self::assertSame(3, $resolver->project($user->id)['level']);
            self::assertSame($before, $this->snapshot());
        } elseif ($order === 'worker') {
            self::assertSame(1, $worker->run('synthetic-worker'));
        }
        $payments = app(V2PaymentService::class);
        $payments->confirmSucceeded($event->id);
        $payments->confirmSucceeded($event->id);
        $worker->run('synthetic-worker');
        self::assertSame(4, $resolver->project($user->id)['level']);
        self::assertDatabaseHas('member_rank_states', ['user_id' => $user->id, 'baseline_month' => '2026-12-01', 'baseline_level' => 3, 'last_judged_month' => '2026-12-01']);
        self::assertDatabaseHas('member_rank_month_totals', ['user_id' => $user->id, 'month' => '2026-12-01', 'points' => 100000]);
        self::assertSame(3, DB::table('member_rank_achievements')->where('user_id', $user->id)->count());
        self::assertSame(['promotion', 'demotion', 'promotion'], DB::table('member_rank_changes')->where('user_id', $user->id)->orderBy('id')->pluck('reason')->all());
        self::assertDatabaseHas('wallets', ['user_id' => $user->id, 'paid_balance' => 200000, 'free_balance' => 7500]);
        $before = $this->snapshot();
        self::assertSame(0, $worker->run('synthetic-worker'));
        self::assertSame($before, $this->snapshot());
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
    }

    public function test_monthly_judgment_rolls_back_with_failed_settlement_and_retry(): void
    {
        $this->enableRankFixture();
        $user = $this->rankUser();
        $this->seedRankState($user, 4);
        [$payment, $event] = $this->rankPayment($user, 100000);
        $this->rankClock('2026-10-31T15:00:01Z');
        $fail = true;
        DB::listen(function ($query) use (&$fail): void {
            if ($fail && str_contains($query->sql, 'insert into "member_rank_achievements"')) {
                $fail = false;
                throw new \RuntimeException('Synthetic rank rollback');
            }
        });
        $before = $this->snapshot();
        try {
            app(V2PaymentService::class)->confirmSucceeded($event->id);
            self::fail('Expected synthetic failure');
        } catch (\RuntimeException $exception) {
            self::assertSame('Synthetic rank rollback', $exception->getMessage());
        }
        self::assertSame($before, $this->snapshot());
        self::assertDatabaseHas('payments', ['id' => $payment->id, 'status' => 'created']);
        self::assertSame(0, DB::table('payment_point_grants')->where('payment_id', $payment->id)->count());
        app(V2PaymentService::class)->confirmSucceeded($event->id);
        self::assertSame(4, app(V2MemberRankResolver::class)->project($user->id)['level']);
        self::assertSame(1, DB::table('member_rank_changes')->where('user_id', $user->id)->where('reason', 'demotion')->count());
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
    }

    public function test_off_and_preregistration_do_not_write_monthly_state_or_rewrite_history(): void
    {
        $user = $this->rankUser(true);
        $this->settleRankPayment($user, 10000);
        $resolver = app(V2MemberRankResolver::class);
        $worker = app(V2MemberRankMonthlyWorker::class);
        self::assertFalse($resolver->project($user->id)['enabled']);
        self::assertSame(0, $worker->run('synthetic-off'));
        self::assertSame(0, DB::table('member_rank_monthly_jobs')->count());
        self::assertSame(0, DB::table('member_rank_states')->count());
        $this->enableRankFixture();
        $this->seedRankState($user, 4);
        $this->settleRankPayment($user, 69999);
        DB::table('pre_registration_settings')->where('id', 1)->update(['is_enabled' => true, 'cutoff_at' => '2026-10-31T14:00:00Z']);
        $before = $this->snapshot();
        $createdAt = $user->fresh()->created_at;
        $this->rankClock('2026-10-31T15:00:01Z');
        $projection = $resolver->project($user->id);
        app(V2PreRegistrationService::class)->activate($this->rankAdmin(), (string) Str::uuid7(), ['expected_revision' => 1]);
        self::assertFalse($createdAt->equalTo($user->fresh()->created_at));
        self::assertSame($before, $this->snapshot());
        self::assertSame($projection, $resolver->project($user->id));
        self::assertSame(3, $projection['level']);
        self::assertSame(500, (int) DB::table('point_lots')->whereIn('grant_operation_id',
            DB::table('point_operations')->where('user_id', $user->id)->where('source_type', 'pre_registration')->select('id')
        )->sum('granted_amount'));
    }

    public function test_bounded_worker_resume_expired_lease_and_progress(): void
    {
        $this->enableRankFixture();
        foreach ([4, 5, 7] as $level) {
            $this->seedRankState($this->rankUser(), $level);
        }
        $this->rankClock('2026-10-31T15:00:01Z');
        $worker = app(V2MemberRankMonthlyWorker::class);
        self::assertSame(3, $worker->progress()['remaining']);
        self::assertSame(1, $worker->run('worker-a', 1));
        self::assertSame(2, $worker->progress()['remaining']);
        DB::table('member_rank_monthly_jobs')->update([
            'status' => 'processing', 'lease_owner' => str_repeat('a', 64), 'lease_expires_at' => now()->addSeconds(60),
        ]);
        self::assertSame(0, $worker->run('worker-b', 1));
        $this->rankClock('2026-10-31T15:01:02Z');
        self::assertSame(2, $worker->run('worker-b', 10));
        self::assertSame(0, $worker->progress()['remaining']);
        self::assertFalse($worker->progress()['provisional']);
        self::assertSame('2026-11-01', $worker->progress()['last_completed_month']);
        self::assertSame(3, (int) $worker->progress()['job']->processed_count);
        self::assertSame(3, DB::table('member_rank_changes')->where('reason', 'demotion')->count());
        $this->artisan('v2:member-rank:work', ['--worker' => 'command-test', '--limit' => '1'])->assertSuccessful();
        $this->artisan('v2:member-rank:work', ['--worker' => 'command-test', '--limit' => '0'])->assertExitCode(2);
    }

    public function test_database_guards_reject_invalid_demotion_and_backward_cursor(): void
    {
        $this->enableRankFixture();
        $user = $this->rankUser();
        $this->seedRankState($user, 4);
        $this->rankClock('2026-10-31T15:00:00Z');
        app(V2MemberRankResolver::class)->materialize($user->id);
        $row = (array) DB::table('member_rank_changes')->where('user_id', $user->id)->firstOrFail();
        unset($row['id']);
        foreach ([
            fn () => DB::table('member_rank_changes')->insert($row),
            fn () => DB::table('member_rank_changes')->insert([...$row, 'to_level' => 1, 'judgment_month' => '2026-12-01']),
            fn () => DB::table('member_rank_changes')->where('user_id', $user->id)->update(['rank_points' => 5]),
            fn () => DB::table('member_rank_states')->where('user_id', $user->id)->update(['baseline_month' => '2026-10-01', 'last_judged_month' => null]),
        ] as $operation) {
            try {
                DB::transaction($operation);
                self::fail('Database guard accepted invalid mutation');
            } catch (QueryException) {
                self::assertTrue(true);
            }
        }
    }

    public function test_latest_scheduled_and_canceled_settings_keep_previous_month_judgment(): void
    {
        $this->enableRankFixture();
        $user = $this->rankUser();
        $this->seedRankState($user, 4);
        $this->settleRankPayment($user, 69999);
        $settings = app(V2MemberRankSettingsService::class);
        $context = $this->rankAdmin();
        $ranks = $settings->effectiveAt(CarbonImmutable::now())['ranks'];
        foreach ([1, 2] as $revision) {
            $ranks[3]['reach_line'] = $revision === 1 ? 90000 : 80000;
            $ranks[3]['maintain_line'] = 60000;
            $settings->update($context, (string) Str::uuid7(), [
                'expected_revision' => $revision, 'enabled' => true, 'default_skip_count' => 1, 'ranks' => $ranks,
            ]);
        }
        $settings->update($context, (string) Str::uuid7(), ['expected_revision' => 3], true);
        self::assertSame(100000, $settings->effectiveAt(CarbonImmutable::parse('2026-10-31T15:00:00Z'))['ranks'][3]['reach_line']);
        $settings->update($context, (string) Str::uuid7(), [
            'expected_revision' => 4, 'enabled' => true, 'default_skip_count' => 1, 'ranks' => $ranks,
        ]);
        [$payment, $event] = $this->rankPayment($user, 80000);
        $this->rankClock('2026-10-31T15:00:01Z');
        self::assertSame(3, app(V2MemberRankResolver::class)->project($user->id)['level']);
        app(V2PaymentService::class)->confirmSucceeded($event->id);
        self::assertSame(4, app(V2MemberRankResolver::class)->project($user->id)['level']);
        self::assertSame([1, 5], DB::table('member_rank_changes')->where('user_id', $user->id)->orderBy('id')->pluck('setting_revision')->all());
    }

    public function test_worker_failure_rolls_back_cursor_and_has_bounded_sanitized_retry(): void
    {
        $this->enableRankFixture();
        $user = $this->rankUser();
        $this->seedRankState($user, 4);
        $this->rankClock('2026-10-31T15:00:01Z');
        $fail = true;
        DB::listen(function ($query) use (&$fail): void {
            if ($fail && str_contains($query->sql, 'update "member_rank_states"')) {
                throw new \RuntimeException('Synthetic private detail must not be retained');
            }
        });
        $worker = app(V2MemberRankMonthlyWorker::class);
        foreach ([1, 2, 3] as $attempt) {
            self::assertSame(0, $worker->run('failure-test', 1));
            $job = $worker->progress()['job'];
            self::assertSame($attempt, (int) $job->failures);
            self::assertSame(0, (int) $job->cursor_user_id);
            self::assertSame('monthly_judgment_failed', $job->last_error);
            self::assertSame($attempt === 3 ? 'failed' : 'pending', $job->status);
            self::assertDatabaseHas('member_rank_states', ['user_id' => $user->id, 'level' => 4, 'last_judged_month' => null]);
            self::assertSame(0, DB::table('member_rank_changes')->where('user_id', $user->id)->count());
            $this->rankClock(CarbonImmutable::now()->addMinutes(1)->toIso8601String());
        }
        $fail = false;
        self::assertSame(0, $worker->run('failure-test', 1));
        self::assertTrue($worker->progress()['provisional']);
    }

    public function test_purchase_application_materializes_before_rank_consumers_without_worker(): void
    {
        $this->enableRankFixture();
        $user = $this->rankUser();
        $this->seedRankState($user, 4);
        $this->rankClock('2026-10-31T15:00:01Z');
        $this->rankPayment($user, 10000);
        self::assertDatabaseHas('member_rank_states', ['user_id' => $user->id, 'level' => 3, 'last_judged_month' => '2026-11-01']);
        self::assertSame(0, DB::table('member_rank_monthly_jobs')->count());
        self::assertSame(0, DB::table('member_rank_point_entries')->where('user_id', $user->id)->count());
    }

    private function snapshot(): string
    {
        $snapshot = [];
        foreach (['member_rank_states', 'member_rank_month_totals', 'member_rank_point_entries', 'member_rank_changes', 'member_rank_achievements', 'member_rank_entitlements'] as $table) {
            $snapshot[$table] = DB::table($table)->orderBy('user_id')->get()->all();
        }

        return json_encode($snapshot, JSON_THROW_ON_ERROR);
    }
}
