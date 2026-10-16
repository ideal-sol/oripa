<?php

namespace Tests\V2;

use App\Domain\Identity\Enums\V2AdminRole;
use App\Domain\Identity\Services\V2PasswordPolicy;
use App\Domain\Identity\Services\V2SessionPolicy;
use App\Domain\MemberRank\Services\V2MemberRankSettingsService;
use App\Models\V2\Admin;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\V2TimestampFixture;
use Tests\TestCase;

final class MemberRankSettingsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config([
            'app.key' => 'base64:'.base64_encode(str_repeat('r', 32)),
            'cache.default' => 'array',
            'v2_identity.origins.admin' => 'https://admin.example.test',
            'v2_audit.active_hmac_key_version' => 'v1',
            'v2_audit.hmac_keys.v1' => 'base64:'.base64_encode(str_repeat('a', 32)),
        ]);
        Cache::store('array')->clear();
        $this->clock('2026-10-16T00:00:00Z');
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

    public function test_audit_failure_rolls_back_revision_values_and_idempotency(): void
    {
        $token = $this->adminSession(V2AdminRole::Admin);
        $key = (string) Str::uuid7();
        config(['v2_audit.business_timezone' => null]);
        $this->mutate($token, $this->payload(), $key)->assertStatus(500);
        self::assertSame(1, (int) DB::table('member_rank_settings')->value('revision'));
        self::assertSame(1, DB::table('member_rank_setting_versions')->count());
        self::assertSame(7, DB::table('member_rank_setting_values')->count());
        config(['v2_audit.business_timezone' => 'Asia/Tokyo']);
        $this->mutate($token, $this->payload(), $key)->assertOk()->assertJsonPath('idempotent_replay', false);
        self::assertSame(1, DB::table('audit_logs')->where('action_code', 'member_rank.settings.updated')->count());
    }

    public function test_initial_values_and_roles(): void
    {
        $this->getJson('/admin/api/v2/settings/member-ranks')->assertUnauthorized();
        $this->getJson('/admin/api/v2/settings/member-ranks/revisions')->assertUnauthorized();
        foreach (V2AdminRole::cases() as $role) {
            $token = $this->adminSession($role);
            $response = $this->read($token)->assertOk()->assertJsonPath('data.enabled', false)
                ->assertJsonPath('data.activated_at', null)->assertJsonPath('data.scheduled', null);
            $ranks = $response->json('data.current.ranks');
            self::assertSame(V2MemberRankSettingsService::CODES, array_column($ranks, 'code'));
            self::assertSame([null, 10000, 30000, 100000, 300000, 700000, 1500000], array_column($ranks, 'reach_line'));
            self::assertSame([null, 5000, 20000, 70000, 200000, 500000, 1000000], array_column($ranks, 'maintain_line'));
            self::assertSame([0, 500, 2000, 5000, 20000, 50000, 100000], array_column($ranks, 'promotion_bonus'));
            self::assertSame([0, 50, 100, 150, 200, 300, 400], array_column($ranks, 'purchase_bonus_rate_bp'));
            $this->asAdmin($token)->getJson('/admin/api/v2/settings/member-ranks/revisions')->assertOk();
        }
        $operator = $this->adminSession(V2AdminRole::Operator);
        $this->mutate($operator, $this->payload())->assertForbidden();
        $this->mutate($operator, ['expected_revision' => 1], null, true)->assertForbidden();
        foreach ([V2AdminRole::Owner, V2AdminRole::Admin] as $role) {
            $revision = (int) DB::table('member_rank_settings')->value('revision');
            $this->mutate($this->adminSession($role), [...$this->payload(), 'expected_revision' => $revision])
                ->assertOk()->assertJsonPath('data.revision', $revision + 1);
        }
    }

    public function test_append_only_schedule_is_idempotent_audited_and_balance_neutral(): void
    {
        $token = $this->adminSession(V2AdminRole::Admin);
        $key = (string) Str::uuid7();
        $counts = [];
        foreach (['wallets', 'point_lots', 'point_operations', 'point_ledger_entries', 'payments', 'pre_registration_grants'] as $table) {
            $counts[$table] = DB::table($table)->count();
        }
        $first = $this->mutate($token, $this->payload(), $key)->assertOk()
            ->assertJsonPath('data.current.revision', 1)->assertJsonPath('data.scheduled.revision', 2)
            ->assertJsonPath('data.scheduled.ranks.1.promotion_bonus', 600)
            ->assertJsonPath('data.scheduled.effective_at', '2026-10-31T15:00:00+00:00');
        $replay = $this->mutate($token, $this->payload(), $key)->assertOk()->assertHeader('Idempotency-Replayed', 'true');
        self::assertSame(
            \Illuminate\Support\Arr::sortRecursive($first->json('data')),
            \Illuminate\Support\Arr::sortRecursive($replay->json('data'))
        );
        $this->mutate($token, [...$this->payload(), 'default_skip_count' => 2], $key)
            ->assertConflict()->assertJsonPath('code', 'IDEMPOTENCY_KEY_REUSED');
        $this->mutate($token, $this->payload())->assertConflict()
            ->assertJsonPath('code', 'MEMBER_RANK_SETTINGS_REVISION_CONFLICT');
        self::assertSame(2, DB::table('member_rank_setting_versions')->count());
        self::assertSame(1, DB::table('audit_logs')->where('action_code', 'member_rank.settings.updated')->count());
        foreach ($counts as $table => $count) {
            self::assertSame($count, DB::table($table)->count());
        }
        $this->asAdmin($token)->getJson('/admin/api/v2/settings/member-ranks/revisions')->assertOk()
            ->assertJsonPath('items.0.before.ranks.1.promotion_bonus', 500)
            ->assertJsonPath('items.0.after.ranks.1.promotion_bonus', 600);
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
    }

    public function test_latest_schedule_switches_exactly_at_jst_month_boundary_and_retains_past_version(): void
    {
        $this->clock('2026-10-31T14:50:00Z');
        $token = $this->adminSession(V2AdminRole::Owner);
        $this->mutate($token, $this->payload())->assertOk();
        $changed = [...$this->payload(), 'expected_revision' => 2];
        $changed['ranks'][1]['promotion_bonus'] = 700;
        $this->mutate($token, $changed)->assertOk();
        $service = app(V2MemberRankSettingsService::class);
        $this->clock('2026-10-31T14:59:59Z');
        $this->read($token)->assertOk()->assertJsonPath('data.current.ranks.1.promotion_bonus', 500)
            ->assertJsonPath('data.scheduled.ranks.1.promotion_bonus', 700);
        foreach (['2026-10-31T15:00:00Z', '2026-10-31T15:00:01Z'] as $instant) {
            $this->clock($instant);
            $this->read($token)->assertOk()->assertJsonPath('data.current.revision', 3)
                ->assertJsonPath('data.current.ranks.1.promotion_bonus', 700)->assertJsonPath('data.scheduled', null);
        }
        self::assertSame(1, $service->effectiveAt(CarbonImmutable::parse('2026-10-01T00:00:00+09:00'))['revision']);
        self::assertSame(3, $service->effectiveAt(CarbonImmutable::parse('2027-01-01T00:00:00+09:00'))['revision']);
        $this->mutate($token, ['expected_revision' => 3], null, true)->assertConflict()
            ->assertJsonPath('code', 'MEMBER_RANK_SCHEDULE_NOT_FOUND');
    }

    public function test_cancel_is_append_only_replayable_and_can_be_replaced(): void
    {
        $token = $this->adminSession(V2AdminRole::Owner);
        $this->mutate($token, $this->payload())->assertOk();
        $key = (string) Str::uuid7();
        $this->mutate($token, ['expected_revision' => 2], $key, true)->assertOk()
            ->assertJsonPath('data.revision', 3)->assertJsonPath('data.scheduled', null);
        $this->mutate($token, ['expected_revision' => 2], $key, true)->assertOk()->assertHeader('Idempotency-Replayed', 'true');
        $this->mutate($token, ['expected_revision' => 2], null, true)->assertConflict();
        $this->mutate($token, ['expected_revision' => 3], null, true)->assertConflict()
            ->assertJsonPath('code', 'MEMBER_RANK_SCHEDULE_NOT_FOUND');
        self::assertSame(500, app(V2MemberRankSettingsService::class)->effectiveAt(CarbonImmutable::parse('2026-11-01T00:00:00+09:00'))['ranks'][1]['promotion_bonus']);
        $this->mutate($token, [...$this->payload(), 'expected_revision' => 3])->assertOk()
            ->assertJsonPath('data.scheduled.revision', 4);
        $this->asAdmin($token)->getJson('/admin/api/v2/settings/member-ranks/revisions?before_revision=4')
            ->assertOk()->assertJsonPath('items.0.after.action', 'cancel')->assertJsonPath('items.0.before.revision', 2);
        self::assertSame(4, DB::table('member_rank_setting_versions')->count());
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
    }

    public function test_activation_is_not_ready_and_deactivation_is_permanently_rejected(): void
    {
        $token = $this->adminSession(V2AdminRole::Owner);
        $this->mutate($token, [...$this->payload(), 'enabled' => true])->assertConflict()
            ->assertJsonPath('code', 'MEMBER_RANK_ACTIVATION_NOT_READY');
        self::assertSame(1, DB::table('member_rank_setting_versions')->count());
        DB::table('member_rank_settings')->where('id', 1)->update(['enabled' => true, 'activated_at' => '2026-10-16T00:00:00Z']);
        $this->mutate($token, $this->payload())->assertConflict()
            ->assertJsonPath('code', 'MEMBER_RANK_DEACTIVATION_FORBIDDEN');
        foreach ([
            fn () => DB::table('member_rank_settings')->update(['enabled' => false, 'activated_at' => null]),
            fn () => DB::table('member_rank_settings')->update(['activated_at' => '2026-10-17T00:00:00Z']),
            fn () => DB::table('member_rank_settings')->delete(),
        ] as $operation) {
            $this->rejectDatabase($operation);
        }
    }

    public function test_validation_rejects_invalid_fixed_levels_ranges_and_numeric_types(): void
    {
        $token = $this->adminSession(V2AdminRole::Owner);
        foreach ([
            [0, 'reach_line', 1], [0, 'promotion_bonus', 1], [1, 'reach_line', 0],
            [2, 'reach_line', 10000], [2, 'maintain_line', 4999], [1, 'maintain_line', 10001],
            [1, 'promotion_bonus', 1000001], [1, 'promotion_bonus', -1],
            [1, 'purchase_bonus_rate_bp', 10001], [1, 'purchase_bonus_rate_bp', 0.5],
            [1, 'purchase_bonus_rate_bp', '50'], [1, 'code', 'gold'],
            [1, 'reach_line', 9007199254740992], [1, 'unexpected', 1],
        ] as [$index, $field, $value]) {
            $payload = $this->payload();
            $payload['ranks'][$index][$field] = $value;
            $this->mutate($token, $payload)->assertUnprocessable()->assertJsonPath('code', 'MEMBER_RANK_SETTINGS_INVALID');
        }
        foreach ([
            [...$this->payload(), 'default_skip_count' => -1],
            [...$this->payload(), 'default_skip_count' => 13],
            [...$this->payload(), 'enabled' => 'false'],
            [...$this->payload(), 'expected_revision' => 0],
            [...$this->payload(), 'unexpected' => true],
            [...$this->payload(), 'ranks' => array_slice($this->payload()['ranks'], 1)],
        ] as $payload) {
            $this->mutate($token, $payload)->assertUnprocessable();
        }
        $this->mutate($token, $this->payload(), '')->assertUnprocessable();
        self::assertSame(1, DB::table('member_rank_setting_versions')->count());
    }

    public function test_database_rejects_history_mutation_and_incomplete_versions(): void
    {
        foreach (['member_rank_setting_versions', 'member_rank_setting_values'] as $table) {
            $this->rejectDatabase(fn () => DB::table($table)->delete());
        }
        $this->rejectDatabase(fn () => DB::table('member_rank_setting_versions')->update(['default_skip_count' => 2]));
        $this->rejectDatabase(fn () => DB::table('member_rank_setting_values')->update(['promotion_bonus' => 0]));
        $this->adminSession(V2AdminRole::Admin);
        $this->rejectDatabase(function (): void {
            DB::table('member_rank_setting_versions')->insert([
                'revision' => 2, 'public_id' => (string) Str::uuid7(), 'action' => 'schedule',
                'effective_at' => '2026-10-31T15:00:00Z', 'saved_at' => '2026-10-16T00:00:00Z',
                'saved_by_admin_id' => DB::table('admins')->value('id'), 'before_revision' => 1,
                'default_skip_count' => 1,
            ]);
            DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
        });
    }

    public function test_mutations_require_csrf_and_admin_realm(): void
    {
        $token = $this->adminSession(V2AdminRole::Admin);
        $this->asAdmin($token)->withHeaders(['Origin' => 'https://untrusted.example.test'])
            ->putJson('/admin/api/v2/settings/member-ranks', $this->payload())->assertForbidden();
        Auth::forgetGuards();
        $this->withUnencryptedCookie('__Host-oripa_admin_session', '')->withUnencryptedCookie('__Host-oripa_user_session', $token)
            ->getJson('/admin/api/v2/settings/member-ranks')->assertUnauthorized();
    }

    private function rejectDatabase(callable $operation): void
    {
        try {
            DB::transaction($operation);
            self::fail('Invalid database mutation was accepted.');
        } catch (QueryException) {
            self::assertTrue(true);
        }
    }

    private function payload(): array
    {
        $version = app(V2MemberRankSettingsService::class)->effectiveAt(CarbonImmutable::parse('2026-10-01T00:00:00+09:00'));
        $version['ranks'][1]['promotion_bonus'] = 600;

        return ['expected_revision' => 1, 'enabled' => false, 'default_skip_count' => 1, 'ranks' => $version['ranks']];
    }

    private function clock(string $instant): void
    {
        Carbon::setTestNow($instant);
        CarbonImmutable::setTestNow($instant);
    }

    private function read(string $token)
    {
        return $this->asAdmin($token)->getJson('/admin/api/v2/settings/member-ranks');
    }

    private function asAdmin(string $token): static
    {
        Auth::forgetGuards();

        return $this->withCredentials()->withUnencryptedCookie('__Host-oripa_admin_session', $token);
    }

    private function mutate(string $token, array $payload, ?string $key = null, bool $cancel = false)
    {
        $csrf = str_repeat('c', 64);
        $client = $this->asAdmin($token)->withServerVariables(['HTTPS' => 'on'])
            ->withUnencryptedCookie('__Host-oripa_admin_xsrf', $csrf)
            ->withHeaders([
                'Origin' => 'https://admin.example.test', 'Sec-Fetch-Site' => 'same-origin',
                'X-XSRF-TOKEN' => $csrf, 'Idempotency-Key' => $key ?? (string) Str::uuid7(),
            ]);

        return $cancel
            ? $client->deleteJson('/admin/api/v2/settings/member-ranks/scheduled', $payload)
            : $client->putJson('/admin/api/v2/settings/member-ranks', $payload);
    }

    private function adminSession(V2AdminRole $role): string
    {
        $email = 'member-rank-'.Str::uuid7().'@example.test';
        $admin = Admin::query()->create([
            'email_display' => $email, 'email_normalized' => $email, 'email_verified_at' => now(),
            'password_hash' => app(V2PasswordPolicy::class)->hash('synthetic member rank password'),
            'role' => $role, 'state' => 'active',
        ]);
        $token = app(V2SessionPolicy::class)->issueOpaqueSessionId();
        DB::table('admin_sessions')->insert(V2TimestampFixture::attributes([
            'session_id_hash' => app(V2SessionPolicy::class)->hashSessionId($token), 'admin_id' => $admin->id,
            'mfa_verified_at' => now()->subMinutes(6), 'requires_mfa_enrollment' => false,
            'created_at' => now()->subSecond(), 'last_activity_at' => now(),
            'idle_expires_at' => now()->addMinutes(15), 'absolute_expires_at' => now()->addHours(8),
        ]));

        return $token;
    }
}
