<?php

namespace Tests\V2;

use App\Domain\Identity\Contracts\V2AdminAuthorizationContext;
use App\Domain\Identity\Enums\V2AdminRole;
use App\Domain\Identity\Services\V2AdminUserReadService;
use App\Domain\Identity\Services\V2AdminUserStateService;
use App\Domain\MemberRank\Exceptions\V2MemberRankException;
use App\Domain\MemberRank\Services\V2MemberRankAdminReadService;
use App\Domain\MemberRank\Services\V2MemberRankAdminService;
use App\Domain\MemberRank\Services\V2MemberRankResolver;
use App\Domain\MemberRank\Services\V2MemberRankMonthlyWorker;
use App\Domain\Point\Services\V2PointService;
use App\Models\V2\User;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\MemberRankAccountingFixture;
use Tests\TestCase;

final class MemberRankAdminTest extends TestCase
{
    use MemberRankAccountingFixture;

    private V2AdminAuthorizationContext $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->configureRankFixture();
        DB::beginTransaction();
        $this->enableRankFixture();
        $this->admin = $this->rankAdmin();
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

    public function test_manual_high_rank_without_bonus_earns_each_entitlement_without_false_promotion(): void
    {
        $user = $this->rankUser();
        $this->write($user, 'rank', $this->rankInput());
        self::assertSame(0, DB::table('member_rank_achievements')->where('user_id', $user->id)->count());
        foreach ([[10000, 500], [20000, 2500], [70000, 7500]] as [$delta, $bonus]) {
            $this->write($user, 'points', ['delta' => $delta, 'reason' => '検証']);
            self::assertSame(4, $this->projection($user)['level']);
            self::assertSame($bonus, (int) DB::table('wallets')->where('user_id', $user->id)->value('free_balance'));
        }
        self::assertSame(0, DB::table('member_rank_changes')->where('reason', 'promotion')->where('user_id', $user->id)->count());
        self::assertSame(1, DB::table('member_rank_changes')->where('reason', 'manual')->where('user_id', $user->id)->count());
        self::assertSame(3, DB::table('member_rank_achievements')->where('basis', 'manual_rank_points')->where('user_id', $user->id)->count());
        $this->assertLedger($user);
    }

    public function test_manual_bonus_is_7500_free_180_days_and_paid_reach_never_grants_twice(): void
    {
        $user = $this->rankUser();
        $request = [...$this->rankInput(), 'grant_bonus' => true];
        $result = $this->write($user, 'rank', $request);
        self::assertSame(7500, $result['data']['total_bonus']);
        foreach (DB::table('point_lots')->where('user_id', $user->id)->get() as $lot) {
            self::assertSame('free', $lot->point_type);
            self::assertSame(180, (int) CarbonImmutable::parse($lot->granted_at)->diffInDays(CarbonImmutable::parse($lot->expire_at)));
        }
        $this->settleRankPayment($user, 100000);
        self::assertSame(9000, (int) DB::table('wallets')->where('user_id', $user->id)->value('free_balance'));
        self::assertSame(1500, (int) DB::table('member_rank_purchase_bonuses')->where('user_id', $user->id)->sum('bonus_amount'));
        self::assertSame(3, DB::table('member_rank_achievements')->where('user_id', $user->id)->count());
        $this->write($user, 'points', ['delta' => -100000, 'reason' => '手動付与は回収対象外']);
        self::assertSame(0, DB::table('member_rank_bonus_recoveries')->where('user_id', $user->id)->count());
    }

    public function test_full_recovery_releases_slot_and_reach_grants_exactly_generation_two(): void
    {
        $user = $this->rankUser();
        $this->write($user, 'points', ['delta' => 10000, 'reason' => '加算']);
        $result = $this->write($user, 'points', ['delta' => -10000, 'reason' => '取消']);
        self::assertSame('beginner', $result['data']['after_rank']);
        self::assertSame(500, $result['data']['recoveries'][0]['recovered_amount']);
        self::assertDatabaseHas('member_rank_changes', ['user_id' => $user->id, 'reason' => 'promotion_reversal', 'from_level' => 2, 'to_level' => 1]);
        self::assertDatabaseHas('member_rank_entitlements', ['user_id' => $user->id, 'level' => 2, 'achievement_id' => null]);
        self::assertSame(0, (int) DB::table('wallets')->where('user_id', $user->id)->value('free_balance'));
        $this->write($user, 'points', ['delta' => 10000, 'reason' => '再到達']);
        self::assertDatabaseHas('member_rank_achievements', ['user_id' => $user->id, 'level' => 2, 'generation' => 2]);
        self::assertSame(500, (int) DB::table('wallets')->where('user_id', $user->id)->value('free_balance'));
        $this->assertLedger($user);
    }

    public static function shortfalls(): array
    {
        return [[500, 0, 500], [300, 200, 300], [0, 500, 0]];
    }

    #[DataProvider('shortfalls')]
    public function test_partial_recovery_is_visible_and_prevents_double_recovery_and_regrant(int $spent, int $recovered, int $shortfall): void
    {
        $user = $this->rankUser();
        $this->write($user, 'points', ['delta' => 10000, 'reason' => '到達']);
        if ($spent > 0) {
            app(V2PointService::class)->consume($user->id, $spent, (string) Str::uuid7());
        }
        $this->write($user, 'points', ['delta' => -10000, 'reason' => '取消']);
        self::assertDatabaseHas('member_rank_bonus_recoveries', ['user_id' => $user->id, 'recovered_amount' => $recovered, 'shortfall_amount' => $shortfall]);
        $summary = app(V2MemberRankAdminReadService::class)->summary();
        self::assertCount($shortfall > 0 ? 1 : 0, $summary['reviews']);
        $this->write($user, 'points', ['delta' => 10000, 'reason' => '再到達']);
        self::assertSame($shortfall > 0 ? 1 : 2, DB::table('member_rank_achievements')->where('user_id', $user->id)->count());
        $this->write($user, 'points', ['delta' => -10000, 'reason' => '再取消']);
        self::assertSame($shortfall > 0 ? 1 : 2, DB::table('member_rank_bonus_recoveries')->where('user_id', $user->id)->count());
        $this->assertLedger($user);
    }

    public function test_manual_gold_stays_gold_but_own_bronze_achievement_is_recovered(): void
    {
        $user = $this->rankUser();
        $this->write($user, 'rank', $this->rankInput());
        $this->settleRankPayment($user, 10000);
        $this->write($user, 'points', ['delta' => -10000, 'reason' => '自力到達を取消']);
        self::assertSame(4, $this->projection($user)['level']);
        self::assertDatabaseHas('member_rank_bonus_recoveries', ['user_id' => $user->id, 'requested_amount' => 500, 'shortfall_amount' => 0]);
        self::assertSame(0, DB::table('member_rank_changes')->where('user_id', $user->id)->where('reason', 'promotion_reversal')->count());
    }

    public function test_preview_read_only_and_revision_payload_wallet_and_month_drift_reject_atomically(): void
    {
        $user = $this->rankUser();
        $input = $this->rankInput();
        $counts = $this->counts();
        $preview = app(V2MemberRankAdminService::class)->preview($this->admin, $user->public_id, 'rank', $input);
        self::assertSame($counts, $this->counts());
        $payload = [...$input, 'expected_revision' => $preview['expected_revision'], 'preview_token' => $preview['preview_token']];
        $this->reject(fn () => app(V2MemberRankAdminService::class)->write($this->admin, $user->public_id, 'rank', [...$payload, 'rank' => 'vip'], (string) Str::uuid7()), 409);
        self::assertSame($counts, $this->counts());
        $this->write($user, 'points', ['delta' => 1, 'reason' => '競合']);
        $this->reject(fn () => app(V2MemberRankAdminService::class)->write($this->admin, $user->public_id, 'rank', $payload, (string) Str::uuid7()), 409);
        $pointInput = ['delta' => 1, 'reason' => '月境界'];
        $old = app(V2MemberRankAdminService::class)->preview($this->admin, $user->public_id, 'points', $pointInput);
        $this->rankClock('2026-10-31T15:00:00Z');
        $this->admin = $this->rankAdmin();
        $this->reject(fn () => app(V2MemberRankAdminService::class)->write($this->admin, $user->public_id, 'points',
            [...$pointInput, 'expected_revision' => $old['expected_revision'], 'preview_token' => $old['preview_token']], (string) Str::uuid7()), 409);
        $this->write($user, 'points', $pointInput);
        self::assertDatabaseHas('member_rank_point_entries', ['user_id' => $user->id, 'month' => '2026-11-01', 'applied_delta' => 1]);
    }

    public function test_idempotent_replay_returns_original_and_changed_body_is_rejected(): void
    {
        $user = $this->rankUser();
        $input = [...$this->rankInput(), 'grant_bonus' => true];
        $preview = app(V2MemberRankAdminService::class)->preview($this->admin, $user->public_id, 'rank', $input);
        $payload = [...$input, 'expected_revision' => $preview['expected_revision'], 'preview_token' => $preview['preview_token']];
        $key = (string) Str::uuid7();
        $first = app(V2MemberRankAdminService::class)->write($this->admin, $user->public_id, 'rank', $payload, $key);
        $counts = $this->counts();
        $second = app(V2MemberRankAdminService::class)->write($this->admin, $user->public_id, 'rank', $payload, $key);
        self::assertSame($this->canonicalResponse($first['data']), $this->canonicalResponse($second['data']));
        self::assertSame($this->canonicalResponse($first['data']), $this->canonicalResponse(array_reverse($first['data'], true)));
        self::assertNotSame($this->canonicalResponse(['value' => 1]), $this->canonicalResponse(['value' => '1']));
        self::assertNotSame($this->canonicalResponse(['value' => true]), $this->canonicalResponse(['value' => 1]));
        self::assertNotSame($this->canonicalResponse(['value' => [1, 2]]), $this->canonicalResponse(['value' => [2, 1]]));
        self::assertTrue($second['idempotent_replay']);
        self::assertSame($counts, $this->counts());
        $this->reject(fn () => app(V2MemberRankAdminService::class)->write($this->admin, $user->public_id, 'rank', [...$payload, 'reason' => '変更'], $key), 409);
    }

    public function test_second_transfer_requires_last_history_confirmation_and_preserves_first_timestamp(): void
    {
        $user = $this->rankUser();
        $input = [...$this->rankInput(), 'kind' => 'transfer'];
        $this->write($user, 'rank', $input);
        $first = app(V2MemberRankAdminReadService::class)->detail($user->id);
        $this->reject(fn () => $this->write($user, 'rank', $input), 409);
        $this->write($user, 'rank', $input, ['confirmed_transfer_id' => $first['last_transfer']['id']]);
        $second = app(V2MemberRankAdminReadService::class)->detail($user->id);
        self::assertNotSame($first['last_transfer']['id'], $second['last_transfer']['id']);
        self::assertSame($first['transferred_at'], $second['transferred_at']);
        $this->write($user, 'rank', $this->rankInput());
        self::assertSame($first['transferred_at'], app(V2MemberRankAdminReadService::class)->detail($user->id)['transferred_at']);
    }

    public static function skipCounts(): array
    {
        return [[0, 3], [1, 4], [2, 4], [12, 4]];
    }

    #[DataProvider('skipCounts')]
    public function test_manual_skip_overwrites_and_lazy_month_boundary_uses_new_baseline(int $skip, int $novemberLevel): void
    {
        $user = $this->rankUser();
        $this->write($user, 'rank', [...$this->rankInput(), 'skip_count' => 12]);
        $this->write($user, 'rank', [...$this->rankInput(), 'skip_count' => $skip]);
        self::assertSame($skip, $this->projection($user)['skip_count']);
        $this->rankClock('2026-10-31T15:00:00Z');
        self::assertSame($novemberLevel, $this->projection($user)['level']);
        self::assertSame(max(0, $skip - 1), $this->projection($user)['skip_count']);
        if ($skip === 1) {
            $this->rankClock('2026-11-30T15:00:00Z');
            self::assertSame(3, $this->projection($user)['level']);
        }
    }

    public function test_list_filters_before_cursor_summary_active_total_and_closed_rank_freezes(): void
    {
        $users = [];
        foreach (range(1, 7) as $level) {
            $user = $this->rankUser();
            if ($level > 1) {
                $this->seedRankState($user, $level);
            }
            $users[] = $user;
        }
        $this->write($users[3], 'rank', [...$this->rankInput(), 'kind' => 'transfer']);
        $closed = $users[6];
        app(V2AdminUserStateService::class)->update($this->admin, $closed->public_id,
            ['status' => 'closed', 'expected_revision' => 1, 'reason' => '退会'], (string) Str::uuid7());
        $this->rankClock('2026-10-31T15:00:00Z');
        $this->admin = $this->rankAdmin();
        $summary = app(V2MemberRankAdminReadService::class)->summary();
        self::assertSame(6, $summary['active_total']);
        self::assertSame(6, array_sum(array_column($summary['ranks'], 'count')));
        self::assertTrue($summary['progress']['provisional']);
        self::assertSame(7, $this->projection($closed)['level']);
        $counts = $this->counts();
        $page = app(V2AdminUserReadService::class)->users($this->admin, null, 1, null, 'active', null, null, 'beginner');
        self::assertCount(1, $page['items']);
        self::assertNotNull($page['next_cursor']);
        $next = app(V2AdminUserReadService::class)->users($this->admin, $page['next_cursor'], 1, null, 'active', null, null, 'beginner');
        self::assertCount(1, $next['items']);
        self::assertNotSame($page['items'][0]['id'], $next['items'][0]['id']);
        $transfer = app(V2AdminUserReadService::class)->users($this->admin, null, 20, null, null, null, null, 'gold', 'true');
        self::assertCount(1, $transfer['items']);
        self::assertSame($counts['member_rank_states'], $this->counts()['member_rank_states']);
        app(V2MemberRankMonthlyWorker::class)->run('m7-synthetic', 100);
        self::assertSame($summary['ranks'], app(V2MemberRankAdminReadService::class)->summary()['ranks']);
        self::assertSame(7, app(V2MemberRankAdminReadService::class)->detail($closed->id)['rank'] === 'vip' ? 7 : 0);
    }

    public function test_audit_and_second_bonus_failures_roll_back_all_mutations(): void
    {
        $user = $this->rankUser();
        $before = $this->counts();
        config(['v2_audit.business_timezone' => null]);
        try {
            $this->write($user, 'rank', [...$this->rankInput(), 'grant_bonus' => true]);
            self::fail('Audit failure was ignored.');
        } catch (\RuntimeException) {
            self::assertSame($before, $this->counts());
        }
        config(['v2_audit.business_timezone' => 'Asia/Tokyo']);
        DB::unprepared("CREATE FUNCTION m7_test_fail_second_bonus() RETURNS trigger LANGUAGE plpgsql AS \$\$ BEGIN IF NEW.level = 3 THEN RAISE EXCEPTION 'synthetic second bonus failure'; END IF; RETURN NEW; END; \$\$; CREATE TRIGGER m7_test_second_bonus BEFORE INSERT ON member_rank_achievements FOR EACH ROW EXECUTE FUNCTION m7_test_fail_second_bonus()");
        try {
            $this->write($user, 'points', ['delta' => 100000, 'reason' => '飛び級']);
            self::fail('Second bonus failure was ignored.');
        } catch (QueryException) {
            self::assertSame($before, $this->counts());
        }
    }

    public static function invalidInputs(): array
    {
        return [['points', ['delta' => 0]], ['points', ['delta' => -1]], ['points', ['delta' => 1.5]],
            ['points', ['delta' => '10']], ['points', ['delta' => 1, 'reason' => '']],
            ['points', ['delta' => 1, 'reason' => "bad\0value"]], ['points', ['delta' => 1, 'reason' => str_repeat('あ', 501)]],
            ['points', ['delta' => 1, 'reason' => "\u{00A0}\u{202F}"]], ['points', ['delta' => 1, 'reason' => "bad\u{0085}value"]],
            ['rank', ['rank' => 'unknown']], ['rank', ['skip_count' => 13]], ['rank', ['grant_bonus' => 'false']], ['rank', ['kind' => 'unknown']]];
    }

    #[DataProvider('invalidInputs')]
    public function test_invalid_inputs_and_over_subtraction_never_mutate(string $operation, array $overrides): void
    {
        $user = $this->rankUser();
        $input = $operation === 'rank' ? $this->rankInput() : ['delta' => 1, 'reason' => '調整'];
        $before = $this->counts();
        $this->reject(fn () => app(V2MemberRankAdminService::class)->preview($this->admin, $user->public_id, $operation, [...$input, ...$overrides]), 422);
        self::assertSame($before, $this->counts());
    }

    public function test_lower_rank_cannot_grant_bonus_same_rank_can_consume_once_and_unicode_reason_survives(): void
    {
        $user = $this->rankUser();
        $this->write($user, 'rank', $this->rankInput());
        $this->reject(fn () => $this->write($user, 'rank', [...$this->rankInput(), 'rank' => 'silver', 'grant_bonus' => true]), 422);
        $this->write($user, 'rank', [...$this->rankInput(), 'grant_bonus' => true, 'reason' => str_repeat('😀', 500)]);
        self::assertSame(7500, (int) DB::table('wallets')->where('user_id', $user->id)->value('free_balance'));
        $this->write($user, 'rank', [...$this->rankInput(), 'rank' => 'bronze', 'skip_count' => 0]);
        self::assertSame(2, $this->projection($user)['baseline_level']);
    }

    private function write(User $user, string $operation, array $input, array $extra = []): array
    {
        $service = app(V2MemberRankAdminService::class);
        $preview = $service->preview($this->admin, $user->public_id, $operation, $input);

        return $service->write($this->admin, $user->public_id, $operation,
            [...$input, 'expected_revision' => $preview['expected_revision'], 'preview_token' => $preview['preview_token'], ...$extra], (string) Str::uuid7());
    }

    public function test_reserved_and_expired_free_lots_are_excluded_from_recovery(): void
    {
        $reserved = $this->rankUser();
        $payment = $this->settleRankPayment($reserved, 10000, 200);
        app(\App\Domain\Payment\V2\Services\V2PaymentService::class)->reserveFullRefund($payment->id, (string) Str::uuid7(), $this->admin->adminId);
        app(V2PointService::class)->consume($reserved->id, 500, (string) Str::uuid7());
        $this->write($reserved, 'points', ['delta' => -10000, 'reason' => '予約済みを回収しない']);
        self::assertDatabaseHas('member_rank_bonus_recoveries', ['user_id' => $reserved->id, 'recovered_amount' => 0, 'shortfall_amount' => 500]);
        self::assertDatabaseHas('wallets', ['user_id' => $reserved->id, 'free_balance' => 200, 'free_reserved_balance' => 200]);
        $expired = $this->rankUser();
        $this->write($expired, 'points', ['delta' => 10000, 'reason' => '到達']);
        app(V2PointService::class)->consume($expired->id, 500, (string) Str::uuid7());
        app(V2PointService::class)->grantFree($expired->id, 300, (string) Str::uuid7(), CarbonImmutable::now()->subDays(181));
        $this->write($expired, 'points', ['delta' => -10000, 'reason' => '失効済みを回収しない']);
        self::assertDatabaseHas('member_rank_bonus_recoveries', ['user_id' => $expired->id, 'recovered_amount' => 0, 'shortfall_amount' => 500]);
        self::assertDatabaseHas('wallets', ['user_id' => $expired->id, 'free_balance' => 300]);
        $this->assertLedger($reserved);
        $this->assertLedger($expired);
    }

    public function test_recovery_can_use_other_current_free_lots_including_real_preregistration_bonus(): void
    {
        $user = $this->rankUser(true);
        $this->settleRankPayment($user, 10000);
        DB::table('pre_registration_settings')->where('id', 1)->update(['is_enabled' => true, 'cutoff_at' => '2026-10-31T14:00:00Z']);
        app(\App\Domain\Identity\Services\V2PreRegistrationService::class)->activate($this->admin, (string) Str::uuid7(), ['expected_revision' => 1]);
        self::assertSame(1000, (int) DB::table('wallets')->where('user_id', $user->id)->value('free_balance'));
        app(V2PointService::class)->consume($user->id, 500, (string) Str::uuid7());
        $this->write($user, 'points', ['delta' => -10000, 'reason' => '現在の利用可能無償から回収']);
        self::assertDatabaseHas('member_rank_bonus_recoveries', ['user_id' => $user->id, 'recovered_amount' => 500, 'shortfall_amount' => 0]);
        self::assertSame(2, DB::table('member_rank_point_entries')->where('user_id', $user->id)->count());
        $this->assertLedger($user);
    }

    public function test_history_filters_and_stable_cursors_preserve_manual_reason_actor_and_transfer(): void
    {
        $user = $this->rankUser();
        $this->write($user, 'rank', $this->rankInput());
        $this->write($user, 'rank', [...$this->rankInput(), 'kind' => 'transfer']);
        $this->write($user, 'points', ['delta' => 10000, 'reason' => '<script>安全な文字列</script>']);
        $read = app(V2MemberRankAdminReadService::class);
        $first = $read->history($user->id, ['limit' => '1', 'history' => 'rank']);
        $second = $read->history($user->id, ['limit' => '1', 'history' => 'rank', 'cursor' => $first['next_cursor']]);
        self::assertNotSame($first['items'][0]['id'], $second['items'][0]['id']);
        self::assertSame('transfer', $first['items'][0]['kind']);
        self::assertSame($this->admin->adminPublicId, $first['items'][0]['actor_id']);
        $all = $read->history(null, ['kind' => 'transfer', 'date_from' => '2026-10-31', 'date_to' => '2026-10-31'], false, true);
        self::assertCount(1, $all['items']);
        $points = $read->history($user->id, ['history' => 'points'], true);
        self::assertSame('<script>安全な文字列</script>', $points['items'][0]['reason_text']);
        self::assertSame($this->admin->adminPublicId, $points['items'][0]['actor_id']);
    }

    public function test_database_rejects_unproven_slot_release_history_mutation_and_wrong_origin(): void
    {
        $user = $this->rankUser();
        $this->write($user, 'points', ['delta' => 10000, 'reason' => '到達']);
        foreach ([
            fn () => DB::table('member_rank_entitlements')->where('user_id', $user->id)->update(['achievement_id' => null]),
            fn () => DB::table('member_rank_point_entries')->where('user_id', $user->id)->update(['applied_delta' => 1]),
            fn () => DB::table('member_rank_changes')->where('user_id', $user->id)->delete(),
            fn () => DB::table('member_rank_entitlements')->where('user_id', $user->id)->delete(),
        ] as $mutation) {
            try {
                DB::transaction($mutation);
                self::fail('Invalid immutable/entitlement mutation was accepted.');
            } catch (QueryException) { self::assertTrue(true); }
        }
        $this->assertLedger($user);
    }

    public function test_audit_rejects_501_unicode_characters_and_accepts_exactly_500(): void
    {
        $audit = app(\App\Domain\Audit\V2\Services\V2AuditLogService::class);
        $record = $audit->record('member_rank.test.unicode', ['reason_text' => str_repeat('😀', 500)]);
        self::assertSame(str_repeat('😀', 500), $record->reason_text);
        foreach ([str_repeat('😀', 501), "invalid\xff"] as $reason) {
            try {
                $audit->record('member_rank.test.unicode', ['reason_text' => $reason]);
                self::fail('Invalid audit Unicode was accepted.');
            } catch (\RuntimeException) { self::assertTrue(true); }
        }
    }

    public function test_shared_decrease_domain_is_reusable_inside_an_owning_transaction_and_replay_safe(): void
    {
        $user = $this->rankUser();
        $this->write($user, 'points', ['delta' => 10000, 'reason' => '到達']);
        $before = $this->counts();
        config(['v2_audit.business_timezone' => null]);
        try {
            $this->write($user, 'points', ['delta' => -10000, 'reason' => '回収の監査障害']);
            self::fail('Recovery audit failure was ignored.');
        } catch (\RuntimeException) { self::assertSame($before, $this->counts()); }
        config(['v2_audit.business_timezone' => 'Asia/Tokyo']);
        DB::transaction(function () use ($user): void {
            DB::table('users')->where('id', $user->id)->lockForUpdate()->firstOrFail();
            $entry = DB::table('member_rank_point_entries')->insertGetId([
                'public_id' => (string) Str::uuid7(), 'user_id' => $user->id, 'month' => '2026-10-01',
                'admin_id' => $this->admin->adminId, 'reason' => 'manual_rank_points', 'reason_text' => 'Shared caller fixture',
                'requested_delta' => -10000, 'applied_delta' => -10000, 'granted_at' => now(), 'recorded_at' => now(),
            ]);
            DB::table('member_rank_month_totals')->where('user_id', $user->id)->update(['points' => 0]);
            $version = app(\App\Domain\MemberRank\Services\V2MemberRankSettingsService::class)->effectiveAt(CarbonImmutable::now());
            $service = app(\App\Domain\MemberRank\Services\V2MemberRankCorrectionService::class);
            self::assertCount(1, $service->applyDecrease($user->id, $entry, $version, CarbonImmutable::now()));
            self::assertSame([], $service->applyDecrease($user->id, $entry, $version, CarbonImmutable::now()));
        });
        self::assertSame(1, DB::table('member_rank_bonus_recoveries')->where('user_id', $user->id)->count());
        $this->assertLedger($user);
    }

    public function test_zero_bonus_consumes_and_full_zero_recovery_reopens_a_new_generation(): void
    {
        $settings = app(\App\Domain\MemberRank\Services\V2MemberRankSettingsService::class);
        $ranks = $settings->read($this->admin)['current']['ranks'];
        $ranks[1]['promotion_bonus'] = 0;
        $settings->update($this->admin, (string) Str::uuid7(), ['expected_revision' => 1, 'enabled' => true,
            'default_skip_count' => 2, 'ranks' => $ranks]);
        $this->rankClock('2026-10-31T15:00:00Z');
        $this->admin = $this->rankAdmin();
        $user = $this->rankUser();
        $this->write($user, 'points', ['delta' => 10000, 'reason' => '0pt資格']);
        self::assertDatabaseHas('member_rank_achievements', ['user_id' => $user->id, 'generation' => 1, 'bonus_amount' => 0]);
        self::assertSame(0, DB::table('point_operations')->where('user_id', $user->id)->count());
        $this->write($user, 'points', ['delta' => -10000, 'reason' => '0pt全額回収']);
        self::assertDatabaseHas('member_rank_bonus_recoveries', ['user_id' => $user->id, 'requested_amount' => 0, 'shortfall_amount' => 0]);
        $this->write($user, 'points', ['delta' => 10000, 'reason' => '0pt再到達']);
        self::assertDatabaseHas('member_rank_achievements', ['user_id' => $user->id, 'generation' => 2, 'bonus_amount' => 0]);
        self::assertSame(0, DB::table('point_operations')->where('user_id', $user->id)->count());
        self::assertSame(2, app(V2MemberRankAdminReadService::class)->detail($user->id)['default_skip_count']);
        $this->assertLedger($user);
    }

    public function test_manual_grant_excludes_existing_entitlements_and_closure_blocks_future_rank_writes(): void
    {
        $user = $this->rankUser();
        $this->write($user, 'points', ['delta' => 10000, 'reason' => 'ブロンズ']);
        $result = $this->write($user, 'rank', [...$this->rankInput(), 'grant_bonus' => true]);
        self::assertSame(7000, $result['data']['total_bonus']);
        self::assertSame(['bronze'], $result['data']['excluded_ranks']);
        [$payment, $event] = $this->rankPayment($user, 100000);
        app(V2AdminUserStateService::class)->update($this->admin, $user->public_id,
            ['status' => 'closed', 'expected_revision' => 1, 'reason' => '退会'], (string) Str::uuid7());
        $counts = $this->counts();
        app(\App\Domain\Payment\V2\Services\V2PaymentService::class)->confirmSucceeded($event->id);
        self::assertSame($counts['member_rank_point_entries'], $this->counts()['member_rank_point_entries']);
        self::assertSame($counts['member_rank_achievements'], $this->counts()['member_rank_achievements']);
        $this->reject(fn () => $this->write($user, 'rank', $this->rankInput()), 409);
        $this->rankClock('2027-06-01T00:00:00Z');
        self::assertSame(4, $this->projection($user)['level']);
        self::assertSame(10000, $this->projection($user)['rank_points']);
    }

    public function test_month_change_while_waiting_for_wallet_requires_new_preview_without_old_month_write(): void
    {
        $user = $this->rankUser();
        $service = app(V2MemberRankAdminService::class);
        $input = ['delta' => 1, 'reason' => 'Wallet lock boundary'];
        $preview = $service->preview($this->admin, $user->public_id, 'points', $input);
        $counts = $this->counts();
        $advance = true;
        DB::listen(function ($query) use (&$advance): void {
            if ($advance && str_contains($query->sql, '"wallets"') && str_contains($query->sql, 'for update')) {
                $advance = false;
                $this->rankClock('2026-10-31T15:00:00Z');
            }
        });
        $this->reject(fn () => $service->write($this->admin, $user->public_id, 'points',
            [...$input, 'expected_revision' => $preview['expected_revision'], 'preview_token' => $preview['preview_token']], (string) Str::uuid7()), 409);
        self::assertFalse($advance);
        self::assertSame($counts, $this->counts());
        $this->write($user, 'points', $input);
        self::assertDatabaseHas('member_rank_point_entries', ['user_id' => $user->id, 'month' => '2026-11-01', 'applied_delta' => 1]);
        self::assertSame(0, DB::table('member_rank_point_entries')->where('user_id', $user->id)->where('month', '2026-10-01')->count());
    }

    private function canonicalResponse(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        if (! array_is_list($value)) {
            ksort($value, SORT_STRING);
        }

        return array_map(fn (mixed $item): mixed => $this->canonicalResponse($item), $value);
    }

    private function rankInput(): array
    {
        return ['rank' => 'gold', 'kind' => 'support', 'reason' => 'お問い合わせ対応', 'skip_count' => 1, 'grant_bonus' => false];
    }

    private function projection(User $user): array
    {
        return app(V2MemberRankResolver::class)->project($user->id);
    }

    private function reject(callable $operation, int $status): void
    {
        try {
            $operation();
            self::fail('Invalid rank request was accepted.');
        } catch (V2MemberRankException $exception) {
            self::assertSame($status, $exception->status);
        }
    }

    private function assertLedger(User $user): void
    {
        $total = (int) DB::table('member_rank_month_totals')->where('user_id', $user->id)->sum('points');
        self::assertSame($total, (int) DB::table('member_rank_point_entries')->where('user_id', $user->id)->sum('applied_delta'));
        self::assertSame((int) DB::table('wallets')->where('user_id', $user->id)->value('free_balance'),
            (int) DB::table('point_ledger_entries')->where('user_id', $user->id)->where('point_type', 'free')->sum('amount_delta'));
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
    }

    private function counts(): array
    {
        $result = [];
        foreach (['member_rank_states', 'member_rank_changes', 'member_rank_manual_changes', 'member_rank_achievements',
            'member_rank_entitlements', 'member_rank_point_entries', 'member_rank_month_totals', 'member_rank_bonus_recoveries',
            'wallets', 'point_lots', 'point_operations', 'point_ledger_entries', 'idempotency_records', 'audit_logs'] as $table) {
            $result[$table] = DB::table($table)->count();
        }

        return $result;
    }
}
