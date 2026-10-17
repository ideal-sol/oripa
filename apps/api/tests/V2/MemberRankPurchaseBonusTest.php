<?php

namespace Tests\V2;

use App\Domain\MemberRank\Services\V2MemberRankAdminService;
use App\Domain\MemberRank\Services\V2MemberRankPurchaseBonusService;
use App\Domain\MemberRank\Services\V2MemberRankSettingsService;
use App\Domain\Payment\V2\Services\V2FincodePaymentService;
use App\Domain\Payment\V2\Services\V2PaymentService;
use App\Domain\Point\Services\V2CurrentUserPointReadService;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\MemberRankAccountingFixture;
use Tests\TestCase;

final class MemberRankPurchaseBonusTest extends TestCase
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
        Auth::forgetGuards();
        Carbon::setTestNow();
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public static function amounts(): array
    {
        return [[5000, 50, 25], [1500, 50, 7], [100, 50, 0], [0, 10000, 0],
            [PHP_INT_MAX, 10000, PHP_INT_MAX], [PHP_INT_MAX, 0, 0],
            [PHP_INT_MAX, 1, intdiv(PHP_INT_MAX, 10000)], [9999, 9999, 9998]];
    }

    #[DataProvider('amounts')]
    public function test_integer_calculation_cannot_overflow(int $amount, int $rate, int $expected): void
    {
        self::assertSame($expected, V2MemberRankPurchaseBonusService::calculate($amount, $rate));
    }

    public static function invalidAmounts(): array
    {
        return [[-1, 50], [100, -1], [100, 10001]];
    }

    #[DataProvider('invalidAmounts')]
    public function test_invalid_amount_or_rate_fails_closed(int $amount, int $rate): void
    {
        $this->expectException(\InvalidArgumentException::class);
        V2MemberRankPurchaseBonusService::calculate($amount, $rate);
    }

    public static function purchases(): array
    {
        return [[2, 5000, 25, 2, 0], [2, 1500, 7, 2, 0], [2, 100, 0, 2, 0],
            [1, 10000, 0, 2, 500], [1, 100000, 0, 4, 7500], [2, 100000, 500, 4, 7500]];
    }

    #[DataProvider('purchases')]
    public function test_pre_increment_rank_exactly_once_separate_lots_and_history(
        int $level, int $amount, int $bonus, int $afterLevel, int $promotion
    ): void {
        $this->enableRankFixture();
        $user = $this->rankUser();
        $this->seedRankState($user, $level);
        [$payment, $event] = $this->rankPayment($user, $amount, 137);
        $service = app(V2PaymentService::class);
        $first = $service->confirmSucceeded($event->id);
        self::assertSame($first->id, $service->confirmSucceeded($event->id)->id);
        $duplicate = $service->recordVerifiedProviderEvent('fincode', 'm4-'.Str::uuid7(), 'payment.succeeded', '{}', [], $payment->id, null, now());
        $service->confirmSucceeded($duplicate->id);
        $snapshot = DB::table('member_rank_purchase_bonuses')->where('payment_id', $payment->id)->sole();
        self::assertSame($level, (int) $snapshot->rank_level);
        self::assertSame($bonus, (int) $snapshot->bonus_amount);
        self::assertSame('2026-10-01', $snapshot->month);
        self::assertSame(1, (int) $snapshot->setting_revision);
        self::assertSame($first->granted_at, $snapshot->granted_at);
        self::assertDatabaseHas('member_rank_states', ['user_id' => $user->id, 'level' => $afterLevel]);
        self::assertDatabaseHas('member_rank_month_totals', ['user_id' => $user->id, 'points' => $amount]);
        self::assertDatabaseHas('wallets', ['user_id' => $user->id, 'paid_balance' => $amount, 'free_balance' => 137 + $bonus + $promotion]);
        self::assertDatabaseHas('payments', ['id' => $payment->id, 'free_point_amount' => 137, 'limited_bonus_point_amount' => 0]);
        $operations = DB::table('point_operations')->where('source_type', 'member_rank_purchase')->where('user_id', $user->id)->get();
        self::assertCount($bonus > 0 ? 1 : 0, $operations);
        if ($bonus > 0) {
            $operation = $operations->sole();
            self::assertSame((int) $operation->id, (int) $snapshot->point_operation_id);
            self::assertSame((int) $payment->id, (int) $operation->source_id);
            $lot = DB::table('point_lots')->where('grant_operation_id', $operation->id)->sole();
            self::assertSame('free', $lot->point_type);
            self::assertSame($bonus, (int) $lot->granted_amount);
            self::assertTrue(CarbonImmutable::parse($lot->expire_at)->equalTo(CarbonImmutable::parse($snapshot->granted_at)->addDays(180)));
            $entry = collect(app(V2CurrentUserPointReadService::class)->history($user, null, 100)['items'])
                ->firstWhere('id', $operation->public_id);
            self::assertSame('購入ボーナス', $entry['reason']['label']);
            self::assertSame('free', $entry['point_type']);
        } else {
            self::assertNull($snapshot->point_operation_id);
        }
        $detail = app(V2FincodePaymentService::class)->show($user, $payment->public_id);
        self::assertSame($bonus, $detail['member_rank_purchase_bonus']['bonus_points']);
        self::assertSame($amount + 137, $detail['grant']['total_points']);
        self::assertSame($detail['member_rank_purchase_bonus'], app(V2FincodePaymentService::class)->history($user, 'succeeded', null, 100)['data'][0]['member_rank_purchase_bonus']);
        self::assertSame(['count' => $bonus > 0 ? 1 : 0, 'amount' => $bonus, 'point_type' => 'free'],
            app(\App\Domain\MemberRank\Services\V2MemberRankAdminReadService::class)->summary()['purchase_bonus']);
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
    }

    public static function methods(): array
    {
        return [['credit_card'], ['paypay'], ['konbini']];
    }

    #[DataProvider('methods')]
    public function test_pending_payment_uses_grant_month_demoted_rank_and_new_setting(string $method): void
    {
        $this->enableRankFixture();
        $user = $this->rankUser();
        $this->seedRankState($user, 3);
        [$payment, $event] = $this->rankPayment($user, 5000, 11, '2026-10-31T14:59:59Z', $method);
        self::assertNull(app(V2FincodePaymentService::class)->show($user, $payment->public_id)['member_rank_purchase_bonus']);
        $settings = app(V2MemberRankSettingsService::class);
        $version = $settings->effectiveAt(CarbonImmutable::now());
        $ranks = $version['ranks'];
        $ranks[1]['purchase_bonus_rate_bp'] = 100;
        $admin = $this->rankAdmin();
        $settings->update($admin, (string) Str::uuid7(), ['expected_revision' => 1, 'enabled' => true, 'default_skip_count' => 1, 'ranks' => $ranks]);
        $this->rankClock('2026-10-31T15:00:00Z');
        $estimate = app(V2MemberRankPurchaseBonusService::class)->estimateContext($user, CarbonImmutable::now());
        self::assertSame(2, $estimate['rank_level']);
        self::assertSame(100, $estimate['rate_basis_points']);
        app(V2PaymentService::class)->confirmSucceeded($event->id);
        self::assertDatabaseHas('member_rank_purchase_bonuses', ['payment_id' => $payment->id,
            'rank_level' => 2, 'rate_basis_points' => 100, 'bonus_amount' => 50,
            'setting_revision' => 2, 'month' => '2026-11-01']);
        self::assertDatabaseHas('member_rank_point_entries', ['payment_id' => $payment->id, 'month' => '2026-11-01']);
        self::assertDatabaseHas('member_rank_changes', ['user_id' => $user->id, 'reason' => 'demotion', 'from_level' => 3, 'to_level' => 2]);
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
    }

    public function test_manual_gold_and_subsequent_purchases_use_correct_rate_without_consuming_entitlements_early(): void
    {
        $this->enableRankFixture();
        $user = $this->rankUser();
        $admin = $this->rankAdmin();
        $service = app(V2MemberRankAdminService::class);
        $input = ['rank' => 'gold', 'kind' => 'other', 'reason' => 'synthetic M4', 'skip_count' => 1, 'grant_bonus' => false];
        $preview = $service->preview($admin, $user->public_id, 'rank', $input);
        $service->write($admin, $user->public_id, 'rank', [...$input, 'expected_revision' => $preview['expected_revision'], 'preview_token' => $preview['preview_token']], (string) Str::uuid7());
        self::assertSame(0, DB::table('member_rank_achievements')->where('user_id', $user->id)->count());
        foreach ([10000, 20000, 70000] as $amount) {
            $payment = $this->settleRankPayment($user, $amount);
            self::assertDatabaseHas('member_rank_purchase_bonuses', ['payment_id' => $payment->id, 'rank_level' => 4, 'rate_basis_points' => 150, 'bonus_amount' => intdiv($amount * 150, 10000)]);
        }
        self::assertSame(3, DB::table('member_rank_achievements')->where('user_id', $user->id)->count());
        self::assertDatabaseHas('wallets', ['user_id' => $user->id, 'paid_balance' => 100000, 'free_balance' => 9000]);
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
    }

    public function test_off_and_pending_have_no_snapshot_or_reward(): void
    {
        $user = $this->rankUser();
        [$payment, $event] = $this->rankPayment($user, 5000);
        self::assertSame(0, DB::table('member_rank_purchase_bonuses')->count());
        app(V2PaymentService::class)->confirmSucceeded($event->id);
        self::assertSame(0, DB::table('member_rank_purchase_bonuses')->count());
        self::assertSame(0, DB::table('member_rank_point_entries')->count());
        self::assertDatabaseHas('wallets', ['user_id' => $user->id, 'paid_balance' => 5000, 'free_balance' => 0]);
    }

    public function test_public_estimates_are_read_only_and_payment_snapshots_are_owner_only(): void
    {
        $this->enableRankFixture();
        $user = $this->rankUser(true);
        $this->seedRankState($user, 3);
        [$payment, $event] = $this->rankPayment($user, 1500, 137);
        $plan = DB::table('point_purchase_plans')->where('id', $payment->point_purchase_plan_id)->sole();
        $anonymous = $this->getJson('/api/v2/point-products')->assertOk()->json('data');
        self::assertNull(collect($anonymous)->firstWhere('id', $plan->public_id)['member_rank_purchase_bonus']);
        Auth::guard('v2_user')->setUser($user);
        $this->rankClock('2026-10-31T15:00:00Z');
        $writes = [];
        DB::listen(function ($query) use (&$writes): void {
            if (preg_match('/^\s*(insert|update|delete)/i', $query->sql)) {
                $writes[] = $query->sql;
            }
        });
        $response = $this->getJson('/api/v2/point-products')->assertOk()->assertHeader('Vary', 'Cookie');
        self::assertStringContainsString('private', $response->headers->get('Cache-Control'));
        self::assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $product = collect($response->json('data'))->firstWhere('id', $plan->public_id);
        self::assertSame('bronze', $product['member_rank_purchase_bonus']['rank_code']);
        self::assertSame(7, $product['member_rank_purchase_bonus']['bonus_points']);
        self::assertTrue($product['member_rank_purchase_bonus']['is_estimate']);
        self::assertSame('free', $product['member_rank_purchase_bonus']['point_type']);
        self::assertStringContainsString('お支払い完了時のランクで付与されます', $product['member_rank_purchase_bonus']['notice']);
        self::assertSame(1637, $product['grant']['total_points']);
        self::assertSame([], $writes);
        self::assertDatabaseHas('member_rank_states', ['user_id' => $user->id, 'level' => 3, 'baseline_month' => '2026-10-01']);
        $this->getJson('/api/v2/payments/'.$payment->public_id)->assertOk()->assertJsonPath('member_rank_purchase_bonus', null);
        app(V2PaymentService::class)->confirmSucceeded($event->id);
        $detail = $this->getJson('/api/v2/payments/'.$payment->public_id)->assertOk()->assertJsonPath('member_rank_purchase_bonus.bonus_points', 7)->json();
        $history = $this->getJson('/api/v2/me/payments?view=succeeded&limit=100')->assertOk()->assertJsonPath('data.0.member_rank_purchase_bonus.bonus_points', 7)->json();
        file_put_contents('/tmp/m4-public-responses.json', json_encode(['products' => $response->json(), 'payment' => $detail, 'history' => $history], JSON_THROW_ON_ERROR));
        Auth::guard('v2_user')->setUser($this->rankUser(true));
        $this->getJson('/api/v2/payments/'.$payment->public_id)->assertNotFound();
        Auth::guard('v2_user')->setUser($user);
        DB::table('user_phone_numbers')->where('user_id', $user->id)->update(['revoked_at' => now()]);
        $unverified = $this->getJson('/api/v2/point-products')->assertOk()->json('data');
        self::assertNull(collect($unverified)->firstWhere('id', $plan->public_id)['member_rank_purchase_bonus']);
    }

    public function test_next_payment_uses_newly_promoted_rank_but_original_keeps_bronze_rate(): void
    {
        $this->enableRankFixture();
        $user = $this->rankUser();
        $this->seedRankState($user, 2);
        $first = $this->settleRankPayment($user, 100000);
        $second = $this->settleRankPayment($user, 1000);
        self::assertDatabaseHas('member_rank_purchase_bonuses', ['payment_id' => $first->id, 'rank_level' => 2, 'bonus_amount' => 500]);
        self::assertDatabaseHas('member_rank_purchase_bonuses', ['payment_id' => $second->id, 'rank_level' => 4, 'bonus_amount' => 15]);
        self::assertDatabaseHas('wallets', ['user_id' => $user->id, 'paid_balance' => 101000, 'free_balance' => 8015]);
        self::assertDatabaseHas('member_rank_month_totals', ['user_id' => $user->id, 'points' => 101000]);
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
    }

    public static function manualSkips(): array
    {
        return [[0, 3, 100], [1, 4, 150], [2, 4, 150]];
    }

    #[DataProvider('manualSkips')]
    public function test_pending_bronze_purchase_uses_manual_gold_and_monthly_skip(int $skip, int $level, int $rate): void
    {
        $this->enableRankFixture();
        $user = $this->rankUser();
        $this->seedRankState($user, 2);
        [$payment, $event] = $this->rankPayment($user, 5000, 0, null, 'konbini');
        $service = app(V2MemberRankAdminService::class);
        $admin = $this->rankAdmin();
        $input = ['rank' => 'gold', 'kind' => 'other', 'reason' => 'M4 pending', 'skip_count' => $skip, 'grant_bonus' => false];
        $preview = $service->preview($admin, $user->public_id, 'rank', $input);
        $service->write($admin, $user->public_id, 'rank', [...$input, 'expected_revision' => $preview['expected_revision'], 'preview_token' => $preview['preview_token']], (string) Str::uuid7());
        $this->rankClock('2026-10-31T15:00:01Z');
        app(V2PaymentService::class)->confirmSucceeded($event->id);
        self::assertDatabaseHas('member_rank_purchase_bonuses', ['payment_id' => $payment->id, 'rank_level' => $level, 'rate_basis_points' => $rate, 'bonus_amount' => intdiv(5000 * $rate, 10000)]);
    }

    public function test_setting_resave_and_cancel_preserve_old_month_and_immutable_snapshots(): void
    {
        $this->enableRankFixture();
        $user = $this->rankUser();
        $this->seedRankState($user, 2);
        DB::table('member_rank_states')->where('user_id', $user->id)->update(['skip_count' => 1]);
        $admin = $this->rankAdmin();
        $settings = app(V2MemberRankSettingsService::class);
        $ranks = $settings->effectiveAt(CarbonImmutable::now())['ranks'];
        foreach ([100, 125] as $index => $rate) {
            $ranks[1]['purchase_bonus_rate_bp'] = $rate;
            $settings->update($admin, (string) Str::uuid7(), ['expected_revision' => $index + 1, 'enabled' => true, 'default_skip_count' => 1, 'ranks' => $ranks]);
            $payment = $this->settleRankPayment($user, 100);
            self::assertDatabaseHas('member_rank_purchase_bonuses', ['payment_id' => $payment->id, 'setting_revision' => 1, 'rate_basis_points' => 50, 'bonus_amount' => 0]);
        }
        $settings->update($admin, (string) Str::uuid7(), ['expected_revision' => 3], true);
        $this->rankClock('2026-10-31T15:00:00Z');
        $payment = $this->settleRankPayment($user, 5000);
        self::assertDatabaseHas('member_rank_purchase_bonuses', ['payment_id' => $payment->id, 'setting_revision' => 4, 'rate_basis_points' => 50, 'bonus_amount' => 25]);
        self::assertSame(2, DB::table('member_rank_purchase_bonuses')->where('setting_revision', 1)->count());
    }

    public function test_preregistration_free500_and_created_at_change_do_not_change_purchase_basis(): void
    {
        $this->enableRankFixture();
        $user = $this->rankUser(true);
        $this->seedRankState($user, 2);
        $before = $this->settleRankPayment($user, 1500, 17);
        $createdAt = $user->created_at->toIso8601String();
        DB::table('pre_registration_settings')->where('id', 1)->update(['is_enabled' => true, 'cutoff_at' => '2026-10-31T14:00:00Z']);
        app(\App\Domain\Identity\Services\V2PreRegistrationService::class)->activate($this->rankAdmin(), (string) Str::uuid7(), ['expected_revision' => 1]);
        self::assertNotSame($createdAt, $user->fresh()->created_at->toIso8601String());
        self::assertSame(1, DB::table('member_rank_purchase_bonuses')->where('user_id', $user->id)->count());
        self::assertDatabaseHas('member_rank_month_totals', ['user_id' => $user->id, 'points' => 1500]);
        $after = $this->settleRankPayment($user->fresh(), 1500, 17);
        foreach ([$before, $after] as $payment) {
            self::assertDatabaseHas('member_rank_purchase_bonuses', ['payment_id' => $payment->id, 'rank_level' => 2, 'bonus_amount' => 7, 'paid_amount' => 1500]);
        }
        self::assertDatabaseHas('wallets', ['user_id' => $user->id, 'paid_balance' => 3000, 'free_balance' => 548]);
        self::assertSame(['count' => 2, 'amount' => 14, 'point_type' => 'free'], app(\App\Domain\MemberRank\Services\V2MemberRankAdminReadService::class)->summary()['purchase_bonus']);
    }

    public function test_first24h_pending_purchase_retains_eligibility_but_uses_later_manual_rank(): void
    {
        $this->enableRankFixture();
        $user = $this->rankUser(true);
        $this->seedRankState($user, 2);
        DB::table('pre_registration_settings')->where('id', 1)->update(['is_enabled' => true, 'cutoff_at' => '2026-10-31T14:00:00Z']);
        app(\App\Domain\Identity\Services\V2PreRegistrationService::class)->activate($this->rankAdmin(), (string) Str::uuid7(), ['expected_revision' => 1]);
        $user->refresh();
        $this->rankClock('2026-11-01T13:59:59Z');
        $plan = DB::table('point_purchase_plans')->insertGetId([
            'public_id' => (string) Str::uuid7(), 'code' => 'm4-first-'.Str::uuid7(), 'version_no' => 1,
            'name' => 'Synthetic first24h', 'audience_code' => 'first_purchase_users', 'amount' => 5000,
            'paid_point_amount' => 5000, 'free_point_amount' => 137, 'currency' => 'JPY',
            'status' => 'published', 'published_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        [$payment, $event] = $this->rankPayment($user, 5000, 137, null, 'konbini', $plan);
        app(V2PaymentService::class)->applyProviderStartResult($payment->id, 'AWAITING', 'requires_action');
        $admin = $this->rankAdmin();
        $service = app(V2MemberRankAdminService::class);
        $input = ['rank' => 'gold', 'kind' => 'other', 'reason' => 'M4 first24h pending', 'skip_count' => 1, 'grant_bonus' => false];
        $preview = $service->preview($admin, $user->public_id, 'rank', $input);
        $service->write($admin, $user->public_id, 'rank', [...$input, 'expected_revision' => $preview['expected_revision'], 'preview_token' => $preview['preview_token']], (string) Str::uuid7());
        $this->rankClock('2026-11-02T13:59:59Z');
        app(V2PaymentService::class)->confirmSucceeded($event->id);
        app(V2PaymentService::class)->confirmSucceeded($event->id);
        self::assertDatabaseHas('member_rank_purchase_bonuses', ['payment_id' => $payment->id, 'rank_level' => 4, 'bonus_amount' => 75]);
        self::assertDatabaseHas('wallets', ['user_id' => $user->id, 'paid_balance' => 5000, 'free_balance' => 712]);
        self::assertFalse(app(\App\Domain\Payment\V2\Services\V2PointPurchaseEligibilityService::class)->eligible(
            $user, DB::table('point_purchase_plans')->where('id', $plan)->sole(), CarbonImmutable::now()
        ));
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
    }

    public function test_database_rejects_snapshot_mutation_wrong_origin_and_extra_bonus_records(): void
    {
        $this->enableRankFixture();
        $user = $this->rankUser();
        $this->seedRankState($user, 2);
        $payment = $this->settleRankPayment($user, 5000);
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
        $snapshot = (array) DB::table('member_rank_purchase_bonuses')->where('payment_id', $payment->id)->sole();
        $lot = (array) DB::table('point_lots')->where('grant_operation_id', $snapshot['point_operation_id'])->sole();
        $entry = (array) DB::table('point_ledger_entries')->where('point_operation_id', $snapshot['point_operation_id'])->sole();
        unset($lot['id'], $entry['id']);
        $lot['public_id'] = (string) Str::uuid7();
        $other = $this->rankUser();
        $this->assertPurchaseConstraint(fn () => DB::table('member_rank_purchase_bonuses')->where('payment_id', $payment->id)->update(['bonus_amount' => 25]));
        $this->assertPurchaseConstraint(fn () => DB::table('member_rank_purchase_bonuses')->where('payment_id', $payment->id)->delete());
        $this->assertPurchaseConstraint(fn () => DB::statement('TRUNCATE member_rank_purchase_bonuses'));
        $this->assertPurchaseConstraint(fn () => DB::table('member_rank_purchase_bonuses')->insert($snapshot));
        foreach (['user_id' => $other->id, 'bonus_amount' => 26, 'point_operation_id' => null, 'rate_basis_points' => 10001] as $field => $value) {
            $this->assertPurchaseConstraint(fn () => DB::table('member_rank_purchase_bonuses')->insert([...$snapshot, $field => $value]));
        }
        $this->assertPurchaseConstraint(fn () => DB::table('point_lots')->insert($lot));
        $this->assertPurchaseConstraint(fn () => DB::table('point_ledger_entries')->insert($entry));
        $this->assertPurchaseConstraint(fn () => DB::table('point_lots')->where('grant_operation_id', $snapshot['point_operation_id'])->update(['user_id' => $other->id]));
        self::assertDatabaseHas('wallets', ['user_id' => $user->id, 'paid_balance' => 5000, 'free_balance' => 25]);
    }

    private function assertPurchaseConstraint(callable $operation): void
    {
        try {
            DB::transaction($operation);
            self::fail('Database must reject invalid purchase origin.');
        } catch (\Illuminate\Database\QueryException) {
            self::assertTrue(true);
        }
    }

    public static function faultTables(): array
    {
        return [['point_operations'], ['point_lots'], ['point_ledger_entries'],
            ['member_rank_purchase_bonuses'], ['audit_logs'], ['outbox_messages']];
    }

    #[DataProvider('faultTables')]
    public function test_failure_rolls_back_entire_payment_and_retry_grants_once(string $table): void
    {
        $this->enableRankFixture();
        $user = $this->rankUser();
        $this->seedRankState($user, 2);
        [$payment, $event] = $this->rankPayment($user, 100000);
        $condition = match ($table) {
            'point_operations' => "NEW.source_type = 'member_rank_purchase'",
            'point_lots' => "EXISTS (SELECT 1 FROM point_operations WHERE id = NEW.grant_operation_id AND source_type = 'member_rank_purchase')",
            'point_ledger_entries' => "EXISTS (SELECT 1 FROM point_operations WHERE id = NEW.point_operation_id AND source_type = 'member_rank_purchase')",
            'audit_logs' => "NEW.action_code = 'point.member_rank_purchase_granted'",
            default => 'TRUE',
        };
        DB::unprepared("CREATE FUNCTION m4_synthetic_failure() RETURNS trigger AS \$\$ BEGIN IF ".$condition." THEN RAISE EXCEPTION 'M4 synthetic failure'; END IF; RETURN NEW; END; \$\$ LANGUAGE plpgsql");
        DB::unprepared('CREATE TRIGGER m4_synthetic_failure BEFORE INSERT ON '.$table.' FOR EACH ROW EXECUTE FUNCTION m4_synthetic_failure()');
        try {
            app(V2PaymentService::class)->confirmSucceeded($event->id);
            self::fail('Synthetic failure must abort settlement.');
        } catch (\Illuminate\Database\QueryException $exception) {
            self::assertStringContainsString('M4 synthetic failure', $exception->getMessage());
        }
        self::assertDatabaseHas('payments', ['id' => $payment->id, 'status' => 'created']);
        foreach (['payment_point_grants', 'member_rank_purchase_bonuses', 'member_rank_point_entries'] as $name) {
            self::assertSame(0, DB::table($name)->where('payment_id', $payment->id)->count());
        }
        self::assertSame(0, DB::table('point_operations')->where('user_id', $user->id)->count());
        self::assertSame(0, DB::table('point_lots')->where('user_id', $user->id)->count());
        self::assertSame(0, DB::table('member_rank_achievements')->where('user_id', $user->id)->count());
        DB::unprepared('DROP TRIGGER m4_synthetic_failure ON '.$table);
        DB::unprepared('DROP FUNCTION m4_synthetic_failure()');
        app(V2PaymentService::class)->confirmSucceeded($event->id);
        app(V2PaymentService::class)->confirmSucceeded($event->id);
        self::assertSame(1, DB::table('member_rank_purchase_bonuses')->where('payment_id', $payment->id)->count());
        self::assertDatabaseHas('wallets', ['user_id' => $user->id, 'paid_balance' => 100000, 'free_balance' => 8000]);
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
    }
}
