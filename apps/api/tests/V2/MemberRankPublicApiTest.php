<?php

namespace Tests\V2;

use App\Domain\Identity\Enums\V2Realm;
use App\Domain\Identity\Services\V2PreRegistrationService;
use App\Domain\Identity\Services\V2SessionManager;
use App\Domain\MemberRank\Services\V2MemberRankSettingsService;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\MemberRankAccountingFixture;
use Tests\TestCase;

final class MemberRankPublicApiTest extends TestCase
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

    public function test_anonymous_sms_admin_realm_and_problem_headers(): void
    {
        $requestId = (string) Str::uuid7();
        $this->withHeader('X-Request-Id', $requestId)->getJson('/api/v2/me/member-rank')
            ->assertUnauthorized()->assertHeader('Content-Type', 'application/problem+json')
            ->assertHeader('X-Request-Id', $requestId)->assertHeader('X-Oripa-Api-Version', '2')
            ->assertHeader('Vary', 'Cookie')->assertJsonPath('code', 'AUTHENTICATION_REQUIRED')
            ->assertJsonPath('request_id', $requestId);
        $user = $this->rankUser();
        Auth::guard('v2_user')->setUser($user);
        $this->getJson('/api/v2/me/member-rank')->assertForbidden()->assertJsonPath('code', 'SMS_VERIFICATION_REQUIRED');
        Auth::forgetGuards();
        $context = $this->rankAdmin();
        Auth::guard('v2_admin')->setUser(\App\Models\V2\Admin::findOrFail($context->adminId));
        $this->getJson('/api/v2/me/member-rank')->assertForbidden();
        $this->getJson('/api/v2/member-ranks')->assertOk()->assertExactJson(['data' => []]);
    }

    public function test_off_is_null_empty_private_and_read_only_even_with_existing_rank_state(): void
    {
        $user = $this->rankUser(true);
        $this->seedRankState($user, 7);
        Auth::guard('v2_user')->setUser($user);
        $before = $this->domainSnapshot();
        $data = array_fill_keys(['rank', 'period', 'rank_points', 'next_rank', 'maintain', 'demotion_protection', 'benefits', 'as_of'], null);
        $response = $this->getJson('/api/v2/me/member-rank')->assertOk()
            ->assertExactJson(['data' => ['enabled' => false, ...$data]])->assertHeader('Vary', 'Cookie');
        self::assertTrue($response->headers->hasCacheControlDirective('private'));
        self::assertTrue($response->headers->hasCacheControlDirective('no-store'));
        $this->getJson('/api/v2/member-ranks')->assertOk()->assertExactJson(['data' => []]);
        self::assertSame($before, $this->domainSnapshot());
    }

    public static function levels(): array
    {
        return array_map(fn (int $level): array => [$level], range(1, 7));
    }

    public function test_cookie_session_reads_without_csrf_and_rejects_revoked_session(): void
    {
        $this->enableRankFixture();
        $user = $this->rankUser(true);
        $session = app(V2SessionManager::class)->issue(V2Realm::User, $user->id);
        Auth::forgetGuards();
        $before = $this->domainSnapshot();
        $this->withCredentials()->withServerVariables(['HTTPS' => 'on'])
            ->withUnencryptedCookie('__Host-oripa_user_session', $session['token'])
            ->getJson('/api/v2/me/member-rank')->assertOk()->assertHeader('Vary', 'Cookie')
            ->assertJsonPath('data.rank.level', 1);
        self::assertSame($before, $this->domainSnapshot());
        DB::table('user_sessions')->where('session_id_hash', hash('sha256', $session['token']))->update(['revoked_at' => now()]);
        Auth::forgetGuards();
        $this->getJson('/api/v2/me/member-rank')->assertUnauthorized()->assertJsonPath('code', 'AUTHENTICATION_REQUIRED');
    }

    public static function reachLines(): array
    {
        return [[2, 10000], [3, 30000], [4, 100000], [5, 300000], [6, 700000], [7, 1500000]];
    }

    #[DataProvider('reachLines')]
    public function test_actual_payment_reaches_each_rank_without_counting_free_bonus(int $level, int $line): void
    {
        $this->enableRankFixture();
        $user = $this->rankUser(true);
        Auth::guard('v2_user')->setUser($user);
        $this->settleRankPayment($user, $line - 1, 111);
        $before = $this->domainSnapshot();
        $this->getJson('/api/v2/me/member-rank')->assertOk()->assertJsonPath('data.rank.level', $level - 1)
            ->assertJsonPath('data.rank_points', $line - 1)->assertJsonPath('data.next_rank.remaining', 1);
        self::assertSame($before, $this->domainSnapshot());
        $this->settleRankPayment($user, 1, 222);
        $before = $this->domainSnapshot();
        $response = $this->getJson('/api/v2/me/member-rank')->assertOk()->assertJsonPath('data.rank.level', $level)
            ->assertJsonPath('data.rank_points', $line);
        self::assertGreaterThanOrEqual(0, $response->json('data.next_rank.remaining') ?? 0);
        self::assertSame($before, $this->domainSnapshot());
    }

    #[DataProvider('levels')]
    public function test_seven_ranks_own_progress_and_no_private_fields(int $level): void
    {
        $this->enableRankFixture();
        $user = $this->rankUser(true);
        $other = $this->rankUser(true);
        $this->seedRankState($user, $level);
        $this->seedRankState($other, $level === 7 ? 1 : 7);
        DB::table('member_rank_month_totals')->insert(['user_id' => $user->id, 'month' => '2026-10-01', 'points' => 42000]);
        Auth::guard('v2_user')->setUser($user);
        $before = $this->domainSnapshot();
        $response = $this->getJson('/api/v2/me/member-rank?user_id='.$other->public_id)->assertOk();
        $data = $response->json('data');
        self::assertSame($level, $data['rank']['level']);
        self::assertSame(V2MemberRankSettingsService::CODES[$level - 1], $data['rank']['code']);
        self::assertSame(42000, $data['rank_points']);
        self::assertSame('2026-10', $data['period']['month']);
        self::assertSame('2026-10-31T15:00:00Z', $data['period']['resets_at']);
        $settings = app(V2MemberRankSettingsService::class)->effectiveAt(CarbonImmutable::now())['ranks'];
        if ($level === 7) {
            self::assertNull($data['next_rank']);
        } else {
            self::assertSame(max(0, $settings[$level]['reach_line'] - 42000), $data['next_rank']['remaining']);
        }
        if ($level === 1) {
            self::assertNull($data['maintain']);
        } else {
            self::assertSame(max(0, $settings[$level - 1]['maintain_line'] - 42000), $data['maintain']['remaining']);
            self::assertSame(42000 >= $settings[$level - 1]['maintain_line'], $data['maintain']['achieved']);
        }
        self::assertNull($data['demotion_protection']);
        foreach (['user_id', 'revision', 'reason', 'saved_by', 'judgments', 'baseline_month', 'email'] as $field) {
            self::assertStringNotContainsString('"'.$field.'"', $response->getContent());
        }
        self::assertSame($before, $this->domainSnapshot());
    }

    public static function boundaries(): array
    {
        return [
            ['2026-10-31T14:59:59Z', '2026-10', '2026-10-31T15:00:00Z'],
            ['2026-10-31T15:00:00Z', '2026-11', '2026-11-30T15:00:00Z'],
            ['2026-11-30T14:59:59Z', '2026-11', '2026-11-30T15:00:00Z'],
            ['2026-11-30T15:00:00Z', '2026-12', '2026-12-31T15:00:00Z'],
            ['2026-12-31T15:00:00Z', '2027-01', '2027-01-31T15:00:00Z'],
            ['2028-02-28T15:00:00Z', '2028-02', '2028-02-29T15:00:00Z'],
            ['2028-02-29T15:00:00Z', '2028-03', '2028-03-31T15:00:00Z'],
        ];
    }

    #[DataProvider('boundaries')]
    public function test_jst_calendar_boundaries_without_worker_or_writes(string $instant, string $month, string $reset): void
    {
        $this->enableRankFixture();
        $user = $this->rankUser(true);
        $this->rankClock($instant);
        Auth::guard('v2_user')->setUser($user);
        $before = $this->domainSnapshot();
        $this->getJson('/api/v2/me/member-rank')->assertOk()->assertJsonPath('data.period.month', $month)
            ->assertJsonPath('data.period.resets_at', $reset)->assertJsonPath('data.rank_points', 0)
            ->assertJsonPath('data.rank.level', 1)->assertJsonPath('data.as_of', $instant);
        self::assertSame($before, $this->domainSnapshot());
    }

    public static function skips(): array
    {
        return array_map(fn (int $count): array => [$count], range(0, 12));
    }

    #[DataProvider('skips')]
    public function test_manual_protection_and_multimonth_lazy_projection(int $skip): void
    {
        $this->enableRankFixture();
        $user = $this->rankUser(true);
        $this->seedRankState($user, 7);
        DB::table('member_rank_states')->where('user_id', $user->id)->update(['skip_count' => $skip]);
        Auth::guard('v2_user')->setUser($user);
        $before = $this->domainSnapshot();
        $current = $this->getJson('/api/v2/me/member-rank')->assertOk()->json('data');
        if ($skip === 0) {
            self::assertNull($current['demotion_protection']);
        } else {
            self::assertSame($skip, $current['demotion_protection']['remaining_judgments']);
            self::assertSame(CarbonImmutable::parse('2026-10-01', 'Asia/Tokyo')->addMonths($skip + 1)
                ->subSecond()->utc()->format('Y-m-d\TH:i:s\Z'), $current['demotion_protection']['guaranteed_until']);
        }
        $this->rankClock('2026-12-31T15:00:00Z');
        $projected = $this->getJson('/api/v2/me/member-rank')->assertOk()->json('data');
        self::assertSame(7 - max(0, 3 - $skip), $projected['rank']['level']);
        self::assertSame(max(0, $skip - 3), $projected['demotion_protection']['remaining_judgments'] ?? 0);
        self::assertSame($before, $this->domainSnapshot());
    }

    public function test_scheduled_settings_and_current_month_maintenance_switch_together(): void
    {
        $this->enableRankFixture();
        $user = $this->rankUser(true);
        $this->seedRankState($user, 3);
        DB::table('member_rank_states')->where('user_id', $user->id)->update(['skip_count' => 1]);
        DB::table('member_rank_month_totals')->insert([
            ['user_id' => $user->id, 'month' => '2026-10-01', 'points' => 42000],
            ['user_id' => $user->id, 'month' => '2026-11-01', 'points' => 100],
        ]);
        Auth::guard('v2_user')->setUser($user);
        $settings = app(V2MemberRankSettingsService::class);
        $context = $this->rankAdmin();
        $ranks = $settings->read($context)['current']['ranks'];
        $old = $this->getJson('/api/v2/member-ranks')->assertOk()->json();
        $ranks[2]['purchase_bonus_rate_bp'] = 101;
        $ranks[2]['maintain_line'] = 21000;
        $settings->update($context, 'm6-scheduled-settings-key', [
            'expected_revision' => 1, 'enabled' => true, 'default_skip_count' => 1, 'ranks' => $ranks,
        ]);
        self::assertSame($old, $this->getJson('/api/v2/member-ranks')->assertOk()->json());
        $this->getJson('/api/v2/me/member-rank')->assertOk()->assertJsonPath('data.maintain.achieved', true);
        $this->rankClock('2026-10-31T15:00:00Z');
        $before = $this->domainSnapshot();
        $this->getJson('/api/v2/me/member-rank')->assertOk()->assertJsonPath('data.rank.level', 3)
            ->assertJsonPath('data.rank_points', 100)->assertJsonPath('data.maintain.line', 21000)
            ->assertJsonPath('data.maintain.remaining', 20900)->assertJsonPath('data.maintain.achieved', false)
            ->assertJsonPath('data.benefits.purchase_bonus.rate_percent', '1.01');
        $list = $this->getJson('/api/v2/member-ranks')->assertOk()->assertJsonCount(7, 'data')
            ->assertJsonPath('data.2.purchase_bonus.rate_percent', '1.01')->assertJsonPath('data.2.promotion_bonus.point_type', 'free');
        self::assertTrue($list->headers->hasCacheControlDirective('no-store'));
        self::assertSame(V2MemberRankSettingsService::CODES, array_column($list->json('data'), 'code'));
        self::assertSame(['code', 'name', 'level', 'reach_line', 'maintain_line', 'promotion_bonus', 'purchase_bonus'], array_keys($list->json('data.2')));
        self::assertSame($before, $this->domainSnapshot());
    }

    public function test_pre_registration_free_grant_and_registration_date_do_not_rewrite_public_rank(): void
    {
        $this->enableRankFixture();
        DB::table('pre_registration_settings')->where('id', 1)->update(['is_enabled' => true, 'cutoff_at' => '2026-10-31T15:00:00Z']);
        $user = $this->rankUser(true);
        $this->settleRankPayment($user, 10000, 111);
        $this->rankClock('2026-10-31T15:00:00Z');
        Auth::guard('v2_user')->setUser($user);
        $before = $this->getJson('/api/v2/me/member-rank')->assertOk()->json();
        $history = DB::table('member_rank_point_entries')->get()->toJson();
        $totals = DB::table('member_rank_month_totals')->get()->toJson();
        $originalCreatedAt = $user->created_at;
        $context = $this->rankAdmin();
        $key = (string) Str::uuid7();
        $service = app(V2PreRegistrationService::class);
        self::assertSame(1, $service->activate($context, $key, ['expected_revision' => 1])['data']['summary']['activated']);
        self::assertTrue($service->activate($context, $key, ['expected_revision' => 1])['idempotent_replay']);
        $user->refresh();
        self::assertFalse($user->created_at->equalTo($originalCreatedAt));
        self::assertSame($before, $this->getJson('/api/v2/me/member-rank')->assertOk()->json());
        self::assertSame($history, DB::table('member_rank_point_entries')->get()->toJson());
        self::assertSame($totals, DB::table('member_rank_month_totals')->get()->toJson());
    }

    private function domainSnapshot(): array
    {
        $snapshot = [];
        foreach (['member_rank_states', 'member_rank_month_totals', 'member_rank_point_entries', 'member_rank_changes',
            'member_rank_settings', 'member_rank_setting_versions', 'member_rank_setting_values',
            'wallets', 'point_operations', 'point_lots', 'point_ledger_entries'] as $table) {
            $snapshot[$table] = DB::table($table)->get()->toJson();
        }

        return $snapshot;
    }
}
