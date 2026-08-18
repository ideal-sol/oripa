<?php

namespace Tests\V2;

use App\Domain\Identity\Enums\V2AdminRole;
use App\Domain\Identity\Enums\V2UserState;
use App\Domain\Identity\Services\V2PasswordPolicy;
use App\Domain\Identity\Services\V2SessionPolicy;
use App\Domain\Referral\Services\V2ReferralRewardService;
use App\Models\V2\Admin;
use App\Models\V2\ReferralPointSetting;
use App\Models\V2\User;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class ReferralPointSettingsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config([
            'app.key' => 'base64:'.base64_encode(str_repeat('r', 32)),
            'cache.default' => 'array',
            'v2_identity.origins.admin' => 'https://admin.example.test',
            'v2_identity.fresh_mfa.minutes' => 5,
            'v2_audit.active_hmac_key_version' => 'v1',
            'v2_audit.hmac_keys.v1' => 'base64:'.base64_encode(str_repeat('a', 32)),
            'v2_audit.business_timezone' => 'Asia/Tokyo',
            'oripa.free_point_expiration_days' => 180,
        ]);
        Cache::store('array')->clear();
        Carbon::setTestNow('2026-08-06T12:00:00Z');
        CarbonImmutable::setTestNow('2026-08-06T12:00:00Z');
        DB::beginTransaction();
    }

    protected function tearDown(): void
    {
        if (DB::transactionLevel() > 0) {
            DB::rollBack();
        }
        Carbon::setTestNow();
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_all_roles_read_but_only_owner_and_admin_update(): void
    {
        $this->getJson('/admin/api/v2/settings/referral-points')->assertUnauthorized();

        foreach (V2AdminRole::cases() as $role) {
            $token = $this->adminSession($role);
            Auth::forgetGuards();
            $this->asAdmin($token)->getJson('/admin/api/v2/settings/referral-points')
                ->assertOk()
                ->assertJsonPath('data.is_enabled', true)
                ->assertJsonPath('data.grant_condition', 'referred_user_sms_verified')
                ->assertJsonPath('data.applies_to', 'future_referrals_only');
        }
        $operator = $this->adminSession(V2AdminRole::Operator);
        Auth::forgetGuards();
        $this->update($operator, $this->payload())->assertForbidden();

        foreach ([V2AdminRole::Owner, V2AdminRole::Admin] as $role) {
            $token = $this->adminSession($role);
            $revision = (int) ReferralPointSetting::query()->whereKey(1)->value('revision');
            Auth::forgetGuards();
            $this->update($token, [...$this->payload(), 'expected_revision' => $revision])
                ->assertOk()
                ->assertJsonPath('data.revision', $revision + 1);
        }
    }

    public function test_update_is_revisioned_idempotent_audited_and_balance_neutral(): void
    {
        $token = $this->adminSession(V2AdminRole::Owner);
        $user = $this->user('neutral');
        $key = (string) Str::uuid7();
        $before = [
            'wallets' => DB::table('wallets')->count(),
            'operations' => DB::table('point_operations')->count(),
            'ledgers' => DB::table('point_ledger_entries')->count(),
        ];
        $payload = $this->payload();

        $first = $this->update($token, $payload, $key)
            ->assertOk()
            ->assertJsonPath('data.referrer_point_amount', 300)
            ->assertJsonPath('data.referred_user_point_amount', 150)
            ->assertJsonPath('data.reward_expiration_days', 90)
            ->assertJsonPath('idempotent_replay', false);
        Auth::forgetGuards();
        $replay = $this->update($token, $payload, $key)
            ->assertOk()->assertJsonPath('idempotent_replay', true);
        self::assertEquals($first->json('data'), $replay->json('data'));
        self::assertSame('true', $replay->headers->get('Idempotency-Replayed'));

        Auth::forgetGuards();
        $this->update($token, [...$payload, 'referrer_point_amount' => 301], $key)
            ->assertConflict()->assertJsonPath('code', 'IDEMPOTENCY_KEY_REUSED');
        Auth::forgetGuards();
        $this->update($token, [...$payload, 'expected_revision' => 1])
            ->assertConflict()->assertJsonPath('code', 'REFERRAL_SETTING_REVISION_CONFLICT');

        self::assertSame($before['wallets'], DB::table('wallets')->count());
        self::assertSame($before['operations'], DB::table('point_operations')->count());
        self::assertSame($before['ledgers'], DB::table('point_ledger_entries')->count());
        self::assertDatabaseMissing('wallets', ['user_id' => $user->id]);
        self::assertSame(1, DB::table('audit_logs')->where('action_code', 'referral.settings.updated')->count());
        self::assertSame(1, DB::table('outbox_messages')->where('event_type', 'referral.point_setting.updated')->count());
    }

    public function test_referral_snapshot_is_future_only_and_reward_is_exactly_once(): void
    {
        $service = app(V2ReferralRewardService::class);
        $referrer = $this->user('referrer');
        $firstReferred = $this->user('first-referred');
        $secondReferred = $this->user('second-referred');
        $setting = ReferralPointSetting::query()->whereKey(1)->firstOrFail();
        $setting->forceFill([
            'referrer_point_amount' => 100,
            'referred_user_point_amount' => 50,
            'reward_expiration_days' => 30,
        ])->save();

        $first = $service->record($firstReferred, $referrer->referral_code);
        $setting->forceFill([
            'referrer_point_amount' => 200,
            'referred_user_point_amount' => 75,
            'reward_expiration_days' => 60,
            'revision' => 2,
        ])->save();
        $second = $service->record($secondReferred, $referrer->referral_code);

        self::assertSame(100, $first->referrer_point_amount);
        self::assertSame(30, $first->reward_expiration_days);
        self::assertSame(200, $second->referrer_point_amount);
        self::assertSame(60, $second->reward_expiration_days);

        $rewarded = $service->rewardForReferredUser($firstReferred);
        self::assertSame('rewarded', $rewarded?->status);
        $service->rewardForReferredUser($firstReferred);
        self::assertDatabaseHas('wallets', ['user_id' => $referrer->id, 'free_balance' => 100]);
        self::assertDatabaseHas('wallets', ['user_id' => $firstReferred->id, 'free_balance' => 50]);
        self::assertSame(2, DB::table('point_operations')->where('source_type', 'referral')->count());
        self::assertSame(2, DB::table('point_ledger_entries')->count());
        $expiries = DB::table('point_lots')->pluck('expire_at');
        foreach ($expiries as $expiry) {
            self::assertSame(
                now()->addDays(180)->startOfSecond()->toIso8601String(),
                CarbonImmutable::parse($expiry)->toIso8601String()
            );
        }
    }

    public function test_validation_and_disabled_snapshot_fail_closed(): void
    {
        $token = $this->adminSession(V2AdminRole::Owner);
        foreach ([
            [...$this->payload(), 'referrer_point_amount' => -1],
            [...$this->payload(), 'referred_user_point_amount' => 1_000_001],
            [...$this->payload(), 'reward_expiration_days' => 0],
            [...$this->payload(), 'unexpected' => true],
        ] as $payload) {
            Auth::forgetGuards();
            $this->update($token, $payload)
                ->assertUnprocessable()->assertJsonPath('code', 'REFERRAL_SETTING_INVALID');
        }

        $setting = ReferralPointSetting::query()->whereKey(1)->firstOrFail();
        $setting->forceFill(['is_enabled' => false])->save();
        $referrer = $this->user('disabled-referrer');
        $referred = $this->user('disabled-referred');
        $service = app(V2ReferralRewardService::class);
        $service->record($referred, $referrer->referral_code);
        $result = $service->rewardForReferredUser($referred);
        self::assertSame('canceled', $result?->status);
        self::assertSame(0, DB::table('point_operations')->where('source_type', 'referral')->count());
    }

    /** @return array<string, int|bool> */
    private function payload(): array
    {
        return [
            'expected_revision' => 1,
            'is_enabled' => true,
            'referrer_point_amount' => 300,
            'referred_user_point_amount' => 150,
            'reward_expiration_days' => 90,
        ];
    }

    private function user(string $suffix): User
    {
        $email = 'referral-'.$suffix.'-'.Str::uuid7().'@example.test';

        return User::query()->create([
            'display_name' => 'Synthetic '.$suffix,
            'email_display' => $email,
            'email_normalized' => $email,
            'email_verified_at' => now(),
            'password_hash' => app(V2PasswordPolicy::class)->hash('valid referral user password'),
            'state' => V2UserState::Active,
        ]);
    }

    private function adminSession(V2AdminRole $role): string
    {
        $email = 'referral-'.$role->value.'-'.Str::uuid7().'@example.test';
        $admin = Admin::query()->create([
            'email_display' => $email,
            'email_normalized' => $email,
            'email_verified_at' => now(),
            'password_hash' => app(V2PasswordPolicy::class)->hash('valid referral admin password'),
            'role' => $role,
            'state' => 'active',
        ]);
        $token = app(V2SessionPolicy::class)->issueOpaqueSessionId();
        $createdAt = now()->subSecond();
        DB::table('admin_sessions')->insert([
            'session_id_hash' => app(V2SessionPolicy::class)->hashSessionId($token),
            'admin_id' => $admin->id,
            'mfa_verified_at' => now(),
            'requires_mfa_enrollment' => false,
            'created_at' => $createdAt,
            'last_activity_at' => now(),
            'idle_expires_at' => now()->addMinutes(15),
            'absolute_expires_at' => $createdAt->copy()->addHours(8),
        ]);

        return $token;
    }

    private function update(string $token, array $payload, ?string $key = null)
    {
        $csrf = str_repeat('c', 64);

        return $this->asAdmin($token)
            ->withServerVariables(['HTTPS' => 'on'])
            ->withUnencryptedCookie('__Host-oripa_admin_xsrf', $csrf)
            ->withHeaders([
                'Origin' => 'https://admin.example.test',
                'Sec-Fetch-Site' => 'same-origin',
                'X-XSRF-TOKEN' => $csrf,
                'Idempotency-Key' => $key ?? (string) Str::uuid7(),
            ])->putJson('/admin/api/v2/settings/referral-points', $payload);
    }

    private function asAdmin(string $token): static
    {
        return $this->withCredentials()
            ->withUnencryptedCookie('__Host-oripa_admin_session', $token);
    }
}
