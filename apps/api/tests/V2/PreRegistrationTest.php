<?php

namespace Tests\V2;

use App\Domain\Identity\Contracts\V2EmailVerificationNotifier;
use App\Domain\Identity\Enums\V2AdminRole;
use App\Domain\Identity\Services\V2IdentityCorrelation;
use App\Domain\Identity\Services\V2PasswordPolicy;
use App\Domain\Identity\Services\V2PreRegistrationService;
use App\Domain\Identity\Services\V2RegistrationQualification;
use App\Domain\Identity\Services\V2SessionPolicy;
use App\Domain\Identity\Services\V2UserAuthenticationService;
use App\Domain\Payment\V2\Services\V2PointPurchaseEligibilityService;
use App\Models\V2\Admin;
use App\Models\V2\User;
use App\Models\V2\UserPhoneNumber;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\V2TimestampFixture;
use Tests\TestCase;

final class PreRegistrationTest extends TestCase
{
    private array $verificationTokens = [];

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'app.key' => 'base64:'.base64_encode(str_repeat('r', 32)),
            'cache.default' => 'array', 'v2_identity.transactions.store' => 'array',
            'v2_identity.origins.user' => 'https://example.test',
            'v2_identity.origins.admin' => 'https://admin.example.test',
            'v2_identity.sms_verification.phone_hmac_key' => 'base64:'.base64_encode(str_repeat('p', 32)),
            'v2_identity.sms_verification.phone_hmac_previous_keys' => [],
            'v2_audit.active_hmac_key_version' => 'v1',
            'v2_audit.hmac_keys.v1' => 'base64:'.base64_encode(str_repeat('a', 32)),
            'v2_audit.business_timezone' => 'Asia/Tokyo',
        ]);
        Cache::store('array')->clear();
        $this->clock('2026-10-20T00:00:00Z');
        DB::beginTransaction();
        $this->mock(V2EmailVerificationNotifier::class)->shouldReceive('send')->andReturnUsing(function (User $user, string $token): void {
            $this->verificationTokens[$user->public_id] = $token;
        });
    }

    protected function tearDown(): void
    {
        while (DB::transactionLevel() > 0) DB::rollBack();
        Carbon::setTestNow();
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_registration_is_dedicated_disabled_by_default_and_rejects_forgery(): void
    {
        $this->getJson('/api/v2/campaigns/pre-registration')->assertOk()->assertJsonPath('data.status', 'not_started');
        $this->register()->assertConflict()->assertJsonPath('code', 'PRE_REGISTRATION_CLOSED');
        $this->enable();
        Cache::store('array')->clear();
        $response = $this->register()->assertAccepted();
        $user = User::query()->where('public_id', $response->json('user_id'))->firstOrFail();
        self::assertEquals($user->created_at, $user->pre_registered_at);
        self::assertSame(0, DB::table('pre_registration_grants')->count());
        self::assertSame(0, DB::table('point_operations')->count());
        $this->register(['is_pre_registered' => true])->assertUnprocessable();
        $this->register(['pre_registered_at' => '2000-01-01T00:00:00Z'])->assertUnprocessable();
        $this->register(['birth_date' => '2015-01-01'])->assertUnprocessable();
        $this->register(['full_name' => ' '])->assertUnprocessable();
        $ordinary = app(V2UserAuthenticationService::class)->register('normal@example.test', 'ordinary valid password', '/pre-register/sms', '192.0.2.99', fullName: 'Synthetic', birthDate: '1990-01-01');
        self::assertNull($ordinary->pre_registered_at);
    }

    public function test_verification_and_resend_preserve_redirect_without_early_welcome_or_points(): void
    {
        $this->enable();
        $response = $this->register()->assertAccepted();
        $publicId = $response->json('user_id');
        $service = app(V2UserAuthenticationService::class);
        $service->resend($publicId, '/');
        self::assertSame('/pre-register/sms', DB::table('user_email_verifications')->where('user_id', User::query()->where('public_id', $publicId)->value('id'))->whereNull('revoked_at')->value('redirect_path'));
        $result = $service->verify($publicId, $this->verificationTokens[$publicId]);
        self::assertSame('/pre-register/sms', $result['redirect_path']);
        self::assertSame('sms_pending', app(V2PreRegistrationService::class)->status($result['user'])['status']);
        self::assertSame(0, DB::table('mail_deliveries')->join('mail_templates', 'mail_templates.id', '=', 'mail_deliveries.mail_template_id')->where('mail_templates.template_key', 'registration_completed')->count());
        self::assertSame(0, DB::table('pre_registration_grants')->count());
        Auth::forgetGuards();
        $this->withCredentials()->withUnencryptedCookie('__Host-oripa_user_session', $result['session']['token'])
            ->getJson('/api/v2/me/pre-registration-bonus')->assertOk()->assertJsonPath('data.status', 'sms_pending')
            ->assertHeader('Cache-Control', 'no-store, private');
    }

    public function test_all_three_cutoff_boundaries_and_open_gate(): void
    {
        $this->enable();
        foreach (['pre_registered_at', 'email_verified_at', 'first_sms_verified_at'] as $column) {
            foreach ([-1, 0, 1] as $offset) {
                $time = CarbonImmutable::parse('2026-10-21T00:00:00Z')->addSeconds($offset);
                $user = $this->applicant([$column => $time]);
                $state = app(V2PreRegistrationService::class)->status($user);
                self::assertSame($offset < 0 ? 'pre_registered' : 'not_eligible', $state['status'], $column.':'.$offset);
            }
        }
        $this->activate()->assertConflict()->assertJsonPath('code', 'PRE_REGISTRATION_NOT_OPEN');
        $this->clock('2026-10-21T00:00:00Z');
        $this->register()->assertConflict();
        $this->activate()->assertOk();
    }

    public function test_activation_is_atomic_once_and_resets_only_successful_qualification(): void
    {
        $this->enable();
        $user = $this->applicant();
        $original = $user->pre_registered_at;
        $sms = $user->first_sms_verified_at;
        $registration = $user->first_registration_qualified_at;
        $late = $this->applicant(['first_sms_verified_at' => CarbonImmutable::parse('2026-10-21T00:00:00Z')]);
        $this->clock('2026-10-21T00:15:00Z');
        DB::table('pre_registration_settings')->where('id', 1)->update(['is_enabled' => false]);
        $token = $this->adminSession();
        $key = (string) Str::uuid7();
        $this->adminRequest($token)->getJson('/admin/api/v2/users?user_id='.$user->public_id.'&date_from=2026-10-19&date_to=2026-10-19')
            ->assertOk()->assertJsonCount(1, 'items');
        $first = $this->activate($token, $key)->assertOk()->assertJsonPath('data.summary.activated', 1);
        $user->refresh();
        self::assertSame(now()->toIso8601String(), $user->created_at->toIso8601String());
        self::assertEquals($original, $user->pre_registered_at);
        self::assertEquals($sms, $user->first_sms_verified_at);
        self::assertEquals($registration, $user->first_registration_qualified_at);
        self::assertEquals($late->pre_registered_at, $late->fresh()->created_at);
        $grant = DB::table('pre_registration_grants')->where('user_id', $user->id)->first();
        self::assertSame(500, $grant->point_amount);
        self::assertSame(now()->addDays(180)->toIso8601String(), CarbonImmutable::parse($grant->expires_at)->toIso8601String());
        self::assertDatabaseHas('wallets', ['user_id' => $user->id, 'free_balance' => 500, 'paid_balance' => 0]);
        self::assertDatabaseHas('point_operations', ['business_key' => 'pre_registration.bonus:'.$user->public_id, 'source_type' => 'pre_registration', 'operation_type' => 'free_grant']);
        self::assertDatabaseHas('point_ledger_entries', ['point_operation_id' => $grant->point_operation_id, 'amount_delta' => 500]);
        self::assertDatabaseHas('point_lots', ['grant_operation_id' => $grant->point_operation_id, 'point_type' => 'free', 'remaining_amount' => 500]);
        self::assertEquals($user->created_at, app(V2RegistrationQualification::class)->origin($user, 'first_registration_qualified_at'));
        self::assertEquals($user->created_at, app(V2PointPurchaseEligibilityService::class)->qualifiedAt($user));
        $replay = $this->activate($token, $key)->assertOk()->assertJsonPath('idempotent_replay', true);
        self::assertEquals($first->json('data'), $replay->json('data'));
        $this->activate()->assertOk();
        self::assertSame(1, DB::table('pre_registration_grants')->count());
        self::assertEquals($user->created_at, $user->fresh()->created_at);
        $this->adminRequest($token)->getJson('/admin/api/v2/users/'.$user->public_id.'/pre-registration')
            ->assertOk()->assertJsonPath('data.point_amount', 500);
        self::assertSame('activated', app(V2PreRegistrationService::class)->status($user)['status']);
        $this->adminRequest($token)->getJson('/admin/api/v2/users?user_id='.$user->public_id.'&date_from=2026-10-19&date_to=2026-10-19')
            ->assertOk()->assertJsonCount(0, 'items');
        $this->adminRequest($token)->getJson('/admin/api/v2/users?user_id='.$user->public_id.'&date_from=2026-10-21&date_to=2026-10-21')
            ->assertOk()->assertJsonCount(1, 'items');
    }

    public function test_partial_failure_rolls_back_one_user_then_retries_at_original_amount(): void
    {
        $this->enable();
        $first = $this->applicant();
        $second = $this->applicant();
        DB::unprepared("CREATE FUNCTION prereg_test_fail() RETURNS trigger AS \$\$ BEGIN IF NEW.user_id = {$second->id} AND NEW.source_type = 'pre_registration' THEN RAISE EXCEPTION 'synthetic failure'; END IF; RETURN NEW; END; \$\$ LANGUAGE plpgsql; CREATE TRIGGER prereg_test_fail BEFORE INSERT ON point_operations FOR EACH ROW EXECUTE FUNCTION prereg_test_fail();");
        $this->clock('2026-10-21T00:00:00Z');
        $this->activate()->assertOk()->assertJsonPath('data.summary.activated', 1)->assertJsonPath('data.summary.failed', 1);
        self::assertEquals($second->created_at, $second->fresh()->created_at);
        self::assertDatabaseMissing('wallets', ['user_id' => $second->id]);
        $this->update(['point_amount' => 700])->assertConflict()->assertJsonPath('code', 'PRE_REGISTRATION_AMOUNT_LOCKED');
        DB::unprepared('DROP TRIGGER prereg_test_fail ON point_operations; DROP FUNCTION prereg_test_fail();');
        $firstActivated = $first->fresh()->created_at;
        $this->clock('2026-10-21T00:20:00Z');
        $this->activate()->assertOk()->assertJsonPath('data.summary.activated', 2)->assertJsonPath('data.summary.failed', 0);
        self::assertEquals($firstActivated, $first->fresh()->created_at);
        self::assertEquals(now(), $second->fresh()->created_at);
        self::assertSame([500], DB::table('pre_registration_grants')->distinct()->pluck('point_amount')->all());
        $this->update(['point_amount' => 700])->assertOk();
        self::assertSame(500, app(V2PreRegistrationService::class)->status($second->fresh())['point_amount']);
    }

    public function test_old_hmac_key_and_withdrawn_owner_cannot_reward_phone_twice(): void
    {
        $this->enable();
        $phone = '+819012340001';
        $first = $this->applicant([], $phone);
        $second = $this->applicant();
        $this->clock('2026-10-21T00:00:00Z');
        DB::unprepared("CREATE FUNCTION prereg_test_fail() RETURNS trigger AS \$\$ BEGIN IF NEW.user_id = {$second->id} AND NEW.source_type = 'pre_registration' THEN RAISE EXCEPTION 'synthetic failure'; END IF; RETURN NEW; END; \$\$ LANGUAGE plpgsql; CREATE TRIGGER prereg_test_fail BEFORE INSERT ON point_operations FOR EACH ROW EXECUTE FUNCTION prereg_test_fail();");
        $this->activate()->assertOk()->assertJsonPath('data.summary.failed', 1);
        DB::unprepared('DROP TRIGGER prereg_test_fail ON point_operations; DROP FUNCTION prereg_test_fail();');
        $first->forceFill(['state' => 'closed'])->save();
        UserPhoneNumber::query()->where('user_id', $first->id)->update(['revoked_at' => now()]);
        config(['v2_identity.sms_verification.phone_hmac_previous_keys' => [config('v2_identity.sms_verification.phone_hmac_key')], 'v2_identity.sms_verification.phone_hmac_key' => 'base64:'.base64_encode(str_repeat('q', 32))]);
        UserPhoneNumber::query()->where('user_id', $second->id)->update(['phone_ciphertext' => Crypt::encryptString($phone), 'phone_hmac' => app(V2IdentityCorrelation::class)->hash($phone)]);
        $this->activate()->assertOk()->assertJsonPath('data.summary.activated', 1)->assertJsonPath('data.summary.excluded', 1);
        self::assertSame('phone_already_rewarded', app(V2PreRegistrationService::class)->status($second)['reason']);
        self::assertSame(1, DB::table('pre_registration_grants')->count());
    }

    public function test_settings_roles_idempotency_revision_preview_and_csrf(): void
    {
        foreach (V2AdminRole::cases() as $role) {
            $token = $this->adminSession($role);
            $this->adminRequest($token)->getJson('/admin/api/v2/settings/pre-registration')->assertOk();
            if ($role === V2AdminRole::Operator) {
                $this->update([], $token)->assertForbidden();
                $this->activate($token)->assertForbidden();
            }
        }
        $token = $this->adminSession();
        $key = (string) Str::uuid7();
        $this->update([], $token, $key)->assertOk()->assertJsonPath('data.revision', 2);
        $this->update([], $token, $key)->assertOk()->assertJsonPath('idempotent_replay', true);
        $this->update(['point_amount' => 501], $token, $key)->assertConflict()->assertJsonPath('code', 'IDEMPOTENCY_KEY_REUSED');
        $this->update([], $token)->assertConflict()->assertJsonPath('code', 'PRE_REGISTRATION_REVISION_CONFLICT');
        $this->applicant(['pre_registered_at' => CarbonImmutable::parse('2026-10-20T12:00:00Z')]);
        $this->adminRequest($token)->postJson('/admin/api/v2/settings/pre-registration/preview', [...$this->payload(), 'expected_revision' => 2, 'cutoff_at' => '2026-10-20T06:00:00Z'])
            ->assertOk()->assertJsonPath('affected_applicants', 1);
        $this->update(['point_amount' => 0])->assertUnprocessable();
        $this->update(['cutoff_at' => null])->assertUnprocessable();
        $this->update(['extra' => true])->assertUnprocessable();
        $stale = $this->adminSession(V2AdminRole::Owner, false);
        $this->activate($stale)->assertForbidden()->assertJsonPath('code', 'FRESH_AUTHENTICATION_REQUIRED');
        $this->adminRequest($token)->withHeader('X-XSRF-TOKEN', 'incorrect')->putJson('/admin/api/v2/settings/pre-registration', $this->payload())->assertForbidden();
    }

    public function test_timestamps_and_grant_records_are_immutable(): void
    {
        $this->enable();
        $user = $this->applicant();
        foreach (['pre_registered_at', 'first_sms_verified_at', 'first_registration_qualified_at'] as $column) {
            try {
                DB::transaction(fn () => DB::table('users')->where('id', $user->id)->update([$column => now()->addDay()]));
                self::fail('Immutable timestamp update must fail: '.$column);
            } catch (\Illuminate\Database\QueryException) {
                self::assertTrue(true);
            }
        }
        $this->clock('2026-10-21T00:00:00Z');
        $this->activate()->assertOk();
        foreach (['UPDATE pre_registration_grants SET point_amount = 501', 'DELETE FROM pre_registration_grants', 'UPDATE users SET created_at = created_at + interval \'1 day\' WHERE id = '.$user->id] as $sql) {
            try { DB::transaction(fn () => DB::statement($sql)); self::fail('Immutable record update must fail.'); }
            catch (\Illuminate\Database\QueryException) { self::assertTrue(true); }
        }
    }

    public function test_actual_sms_verification_never_grants_pre_registration_points(): void
    {
        $this->enable();
        $publicId = $this->register()->assertAccepted()->json('user_id');
        $verified = app(V2UserAuthenticationService::class)->verify($publicId, $this->verificationTokens[$publicId]);
        $user = $verified['user'];
        $request = \Illuminate\Http\Request::create('/api/v2/me/sms-verification', 'POST');
        $request->cookies->set('__Host-oripa_user_session', $verified['session']['token']);
        $service = app(\App\Domain\Identity\Services\V2SmsVerificationService::class);
        $service->send($user, $request, '09012345678');
        $message = \App\Models\V2\OutboxMessage::query()->where('topic', 'identity.sms-verification')->sole();
        $delivery = json_decode(Crypt::decryptString($message->payload['message_ciphertext']), true, flags: JSON_THROW_ON_ERROR);
        $challenge = \App\Models\V2\SmsVerificationChallenge::query()->where('user_id', $user->id)->sole();
        $this->mock(\App\Domain\Sms\Contracts\V2SmsProvider::class)->shouldReceive('deliver')->once()
            ->andReturn(\App\Domain\Sms\Values\V2SmsDeliveryResult::accepted('synthetic-prereg-sms'));
        app(\App\Domain\Sms\Services\V2SmsDeliveryWorker::class)->run('synthetic-prereg-worker', 10);
        $service->verify($user, $request, $challenge->public_id, $delivery['verification_code']);
        self::assertNotNull($user->fresh()->first_sms_verified_at);
        self::assertSame('pre_registered', app(V2PreRegistrationService::class)->status($user->fresh())['status']);
        self::assertSame(0, DB::table('point_operations')->where('source_type', 'pre_registration')->count());
        self::assertSame(0, DB::table('pre_registration_grants')->count());
    }

    public function test_welcome_is_suppressed_only_for_pre_registration_before_open(): void
    {
        $this->enable();
        $auth = app(V2UserAuthenticationService::class);
        $ordinary = $auth->register('welcome@example.test', 'ordinary valid password', '/', '192.0.2.99', fullName: 'Synthetic', birthDate: '1990-01-01');
        self::assertFalse(app(V2PreRegistrationService::class)->suppressWelcome($ordinary));
        $auth->verify($ordinary->public_id, $this->verificationTokens[$ordinary->public_id]);
        self::assertSame(1, DB::table('mail_deliveries')->join('mail_templates', 'mail_templates.id', '=', 'mail_deliveries.mail_template_id')->where('mail_templates.template_key', 'registration_completed')->count());
        $publicId = $this->register()->assertAccepted()->json('user_id');
        $preregistered = User::query()->where('public_id', $publicId)->firstOrFail();
        self::assertTrue(app(V2PreRegistrationService::class)->suppressWelcome($preregistered));
        $this->clock('2026-10-21T00:00:00Z');
        self::assertFalse(app(V2PreRegistrationService::class)->suppressWelcome($preregistered));
        $auth->resend($publicId, '/');
        $auth->verify($publicId, $this->verificationTokens[$publicId]);
        self::assertSame(2, DB::table('mail_deliveries')->join('mail_templates', 'mail_templates.id', '=', 'mail_deliveries.mail_template_id')->where('mail_templates.template_key', 'registration_completed')->count());
        self::assertSame('not_eligible', app(V2PreRegistrationService::class)->status($preregistered->fresh())['status']);
    }

    public function test_first_purchase_24_hour_and_signup_once_origin_reset_only_after_success(): void
    {
        $this->enable();
        $user = $this->applicant();
        $eligibility = app(V2PointPurchaseEligibilityService::class);
        $plan = (object) ['audience_code' => 'first_purchase_users', 'code' => 'synthetic-first-product'];
        $draw = app(\App\Domain\Draw\Services\V2DrawEligibilityService::class);
        $gacha = (object) ['id' => 999999, 'gacha_type' => 'signup_once'];
        $version = (object) ['publish_start_at' => '2026-10-21T00:00:00Z'];
        $this->clock('2026-10-21T00:00:00Z');
        self::assertFalse($eligibility->eligible($user, $plan, CarbonImmutable::now()));
        self::assertFalse($draw->evaluateLogin($user, $gacha, $version, CarbonImmutable::now())['eligible']);
        $this->activate()->assertOk();
        self::assertTrue($draw->evaluateLogin($user, $gacha, $version, CarbonImmutable::now())['eligible']);
        foreach ([-1, 0, 1, 86399, 86400, 86401] as $seconds) {
            self::assertSame($seconds >= 0 && $seconds < 86400,
                $eligibility->eligible($user, $plan, CarbonImmutable::now()->addSeconds($seconds)));
        }
        self::assertSame(0, DB::table('payments')->where('user_id', $user->id)->count());
    }

    private function clock(string $time): void
    {
        Carbon::setTestNow($time);
        CarbonImmutable::setTestNow($time);
    }

    private function enable(): void
    {
        DB::table('pre_registration_settings')->where('id', 1)->update(['is_enabled' => true, 'cutoff_at' => '2026-10-21T00:00:00Z']);
    }

    private function applicant(array $overrides = [], ?string $phone = null): User
    {
        $time = CarbonImmutable::parse('2026-10-19T00:00:00Z');
        $email = 'prereg-'.Str::uuid7().'@example.test';
        $attributes = [...[
            'email_display' => $email, 'email_normalized' => $email, 'password_hash' => app(V2PasswordPolicy::class)->hash('valid synthetic password'),
            'state' => 'active', 'pre_registered_at' => $time, 'email_verified_at' => $time->addSecond(),
            'first_sms_verified_at' => $time->addSeconds(2), 'first_registration_qualified_at' => $time->addSecond(),
        ], ...$overrides];
        $user = new User($attributes);
        $user->forceFill(['created_at' => $attributes['pre_registered_at']])->save();
        $phone ??= '+8190'.str_pad((string) $user->id, 8, '0', STR_PAD_LEFT);
        UserPhoneNumber::query()->create([
            'user_id' => $user->id, 'phone_ciphertext' => Crypt::encryptString($phone),
            'phone_hmac' => app(V2IdentityCorrelation::class)->hash($phone), 'verified_at' => $time->addSeconds(2),
        ]);
        return $user;
    }

    private function register(array $overrides = [])
    {
        $csrf = str_repeat('c', 64);
        return $this->withCredentials()->withServerVariables(['HTTPS' => 'on'])->withUnencryptedCookie('__Host-oripa_user_xsrf', $csrf)
            ->withHeaders(['Origin' => 'https://example.test', 'Sec-Fetch-Site' => 'same-origin', 'X-XSRF-TOKEN' => $csrf])
            ->postJson('/api/v2/auth/pre-register', [...['full_name' => 'Synthetic Member', 'birth_date' => '1990-01-01', 'email' => 'prereg@example.test', 'password' => 'valid preregistration password'], ...$overrides]);
    }

    private function payload(): array
    {
        return ['expected_revision' => 1, 'is_enabled' => true, 'cutoff_at' => '2026-10-21T00:00:00Z', 'point_amount' => 500];
    }

    private function update(array $overrides = [], ?string $token = null, ?string $key = null)
    {
        return $this->adminRequest($token ?? $this->adminSession(), $key)->putJson('/admin/api/v2/settings/pre-registration', [...$this->payload(), ...$overrides]);
    }

    private function activate(?string $token = null, ?string $key = null)
    {
        return $this->adminRequest($token ?? $this->adminSession(), $key)->postJson('/admin/api/v2/settings/pre-registration/activate', ['expected_revision' => 1]);
    }

    private function adminSession(V2AdminRole $role = V2AdminRole::Owner, bool $fresh = true): string
    {
        $email = 'prereg-admin-'.Str::uuid7().'@example.test';
        $admin = Admin::query()->create(['email_display' => $email, 'email_normalized' => $email, 'email_verified_at' => now(), 'password_hash' => app(V2PasswordPolicy::class)->hash('valid admin password'), 'role' => $role, 'state' => 'active']);
        $policy = app(V2SessionPolicy::class);
        $token = $policy->issueOpaqueSessionId();
        DB::table('admin_sessions')->insert(V2TimestampFixture::attributes([
            'session_id_hash' => $policy->hashSessionId($token), 'admin_id' => $admin->id,
            'mfa_verified_at' => now()->subMinutes($fresh ? 0 : 6), 'requires_mfa_enrollment' => false,
            'created_at' => now()->subSecond(), 'last_activity_at' => now(),
            'idle_expires_at' => now()->addMinutes(15), 'absolute_expires_at' => now()->subSecond()->addHours(8),
        ]));
        return $token;
    }

    private function adminRequest(string $token, ?string $key = null): static
    {
        Auth::forgetGuards();
        $csrf = str_repeat('c', 64);
        return $this->withCredentials()->withServerVariables(['HTTPS' => 'on'])
            ->withUnencryptedCookie('__Host-oripa_admin_session', $token)->withUnencryptedCookie('__Host-oripa_admin_xsrf', $csrf)
            ->withHeaders(['Origin' => 'https://admin.example.test', 'Sec-Fetch-Site' => 'same-origin', 'X-XSRF-TOKEN' => $csrf, 'Idempotency-Key' => $key ?? (string) Str::uuid7()]);
    }
}
