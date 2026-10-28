<?php

namespace Tests\V2;

use App\Domain\Identity\Enums\V2Realm;
use App\Domain\Identity\Services\V2PasswordPolicy;
use App\Domain\Identity\Services\V2SessionManager;
use App\Models\V2\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;
use Tests\V2\Fixtures\SmsVerifiedUser;

final class AccountClosureHttpTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:'.base64_encode(str_repeat('a', 32)), 'cache.default' => 'array',
            'mail.default' => 'array', 'v2_identity.origins.user' => 'https://storefront.example.test']);
        Cache::store('array')->clear();
        DB::beginTransaction();
    }

    protected function tearDown(): void
    {
        Auth::forgetGuards();
        while (DB::transactionLevel() > 0) DB::rollBack();
        \Carbon\Carbon::setTestNow(); \Carbon\CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    private function user(bool $password = true, string $state = 'active'): User
    {
        $email = Str::uuid7().'@example.test';
        return SmsVerifiedUser::create(['email_display' => $email, 'email_normalized' => $email,
            'email_verified_at' => now(), 'state' => $state, 'password_login_enabled' => $password,
            'password_hash' => app(V2PasswordPolicy::class)->hash($password ? 'closure test password' : (string) Str::uuid7())]);
    }

    private function browser(User $user): void
    {
        Auth::forgetGuards();
        $session = app(V2SessionManager::class)->issue(V2Realm::User, (int) $user->id);
        $csrf = str_repeat('c', 64);
        $this->withCredentials()->withServerVariables(['HTTPS' => 'on'])
            ->withHeaders(['Origin' => 'https://storefront.example.test', 'Sec-Fetch-Site' => 'same-origin',
                'X-XSRF-TOKEN' => $csrf, 'Idempotency-Key' => (string) Str::uuid7()])
            ->withUnencryptedCookie('__Host-oripa_user_session', $session['token'])
            ->withUnencryptedCookie('__Host-oripa_user_xsrf', $csrf);
    }

    private function input(array $override = []): array
    {
        return array_replace(['confirmed' => true, 'expected_point_balance' => 0,
            'expected_unclaimed_prize_count' => 0, 'password' => 'closure test password'], $override);
    }

    public function test_restricted_user_password_closure_revokes_session_and_cookie(): void
    {
        $user = $this->user(state: 'restricted'); $this->browser($user);
        $this->getJson('/api/v2/me/account-closure')->assertOk()->assertJsonPath('data.verification_method', 'password');
        $response = $this->postJson('/api/v2/me/account-closure', $this->input());
        $response->assertOk()->assertJsonStructure(['data' => ['closed_at']]);
        $response->assertCookieExpired('__Host-oripa_user_session');
        self::assertDatabaseHas('account_closures', ['user_id' => $user->id, 'closed_by' => 'self', 'closure_kind' => 'normal']);
        self::assertSame(0, DB::table('user_sessions')->where('user_id', $user->id)->whereNull('revoked_at')->count());
        Auth::forgetGuards();
        $this->postJson('/api/v2/me/account-closure', $this->input())->assertUnauthorized();
        self::assertSame(1, DB::table('account_closures')->where('user_id', $user->id)->count());
    }

    public function test_wrong_password_consent_stale_and_csrf_are_nonmutating(): void
    {
        $user = $this->user(); $this->browser($user);
        $this->postJson('/api/v2/me/account-closure', $this->input(['password' => 'wrong password']))->assertStatus(422);
        $this->postJson('/api/v2/me/account-closure', $this->input(['confirmed' => false]))->assertStatus(422);
        $this->postJson('/api/v2/me/account-closure', $this->input(['expected_point_balance' => 1]))->assertStatus(409)->assertJsonPath('code', 'ACCOUNT_CLOSURE_STALE');
        $this->withHeader('X-XSRF-TOKEN', 'wrong')->postJson('/api/v2/me/account-closure', $this->input())->assertStatus(403)->assertJsonPath('code', 'CSRF_TOKEN_MISMATCH');
        self::assertSame(0, DB::table('account_closures')->where('user_id', $user->id)->count());
        self::assertSame(0, DB::table('idempotency_records')->where('actor_public_id', $user->public_id)->count());
    }

    public function test_sms_gate_and_password_attempt_throttle(): void
    {
        $user = $this->user(); $this->browser($user);
        DB::table('user_phone_numbers')->where('user_id', $user->id)->update(['revoked_at' => now()]);
        $this->getJson('/api/v2/me/account-closure')->assertForbidden();
        $this->postJson('/api/v2/me/account-closure', $this->input())->assertForbidden();
        DB::table('user_phone_numbers')->where('user_id', $user->id)->update(['revoked_at' => null]);
        $status = null;
        for ($i = 0; $i < 20; $i++) {
            $status = $this->postJson('/api/v2/me/account-closure', $this->input(['password' => 'wrong password']))->status();
            if ($status === 429) break;
        }
        self::assertSame(429, $status);
    }

    public function test_email_code_is_private_purpose_bound_and_invalid_after_five_attempts(): void
    {
        $user = $this->user(false); $this->browser($user);
        $this->getJson('/api/v2/me/account-closure')->assertOk()->assertJsonPath('data.verification_method', 'email_code');
        $code = '123456';
        $id = DB::table('account_closure_codes')->insertGetId(['user_id' => $user->id,
            'code_hash' => hash_hmac('sha256', 'account-closure:'.$user->id.':'.$code, config('app.key')),
            'sent_at' => now()->startOfSecond(), 'expires_at' => now()->startOfSecond()->addMinutes(10)]);
        $input = $this->input(); unset($input['password']); $input['email_code'] = '000000';
        for ($i = 0; $i < 5; $i++) $this->postJson('/api/v2/me/account-closure', $input)->assertStatus(422);
        self::assertDatabaseHas('account_closure_codes', ['id' => $id, 'failed_attempts' => 5]);
        self::assertNotNull(DB::table('account_closure_codes')->where('id', $id)->value('invalidated_at'));
        $input['email_code'] = $code;
        $this->postJson('/api/v2/me/account-closure', $input)->assertStatus(422);
        self::assertSame(0, DB::table('account_closures')->where('user_id', $user->id)->count());
    }

    public function test_email_code_success_and_send_cooldown(): void
    {
        $user = $this->user(false); $this->browser($user);
        $this->postJson('/api/v2/me/account-closure/email-code', [])->assertOk()->assertJsonStructure(['data' => ['masked_email', 'expires_at', 'resend_available_at']]);
        $this->postJson('/api/v2/me/account-closure/email-code', [])->assertStatus(429);
        $row = DB::table('account_closure_codes')->where('user_id', $user->id)->sole();
        self::assertMatchesRegularExpression('/\A[0-9a-f]{64}\z/', $row->code_hash);
        DB::table('account_closure_codes')->where('id', $row->id)->update(['code_hash' => hash_hmac('sha256', 'account-closure:'.$user->id.':654321', config('app.key'))]);
        $input = $this->input(); unset($input['password']); $input['email_code'] = '654321';
        $this->postJson('/api/v2/me/account-closure', $input)->assertOk();
        self::assertNotNull(DB::table('account_closure_codes')->where('id', $row->id)->value('invalidated_at'));
    }

    public function test_email_code_resend_hour_limit_expiry_and_wrong_method(): void
    {
        $at = \Carbon\CarbonImmutable::parse('2026-10-28T12:00:00Z');
        \Carbon\Carbon::setTestNow($at); \Carbon\CarbonImmutable::setTestNow($at);
        $user = $this->user(false); $this->browser($user);
        for ($i = 0; $i < 5; $i++) {
            $instant = $at->addSeconds(60 * $i);
            \Carbon\Carbon::setTestNow($instant); \Carbon\CarbonImmutable::setTestNow($instant);
            $this->postJson('/api/v2/me/account-closure/email-code', [])->assertOk();
        }
        \Carbon\Carbon::setTestNow($at->addMinutes(5)); \Carbon\CarbonImmutable::setTestNow($at->addMinutes(5));
        $this->postJson('/api/v2/me/account-closure/email-code', [])->assertStatus(429);
        self::assertSame(1, DB::table('account_closure_codes')->where('user_id', $user->id)->whereNull('invalidated_at')->count());
        $this->postJson('/api/v2/me/account-closure', $this->input())->assertStatus(422);
        $row = DB::table('account_closure_codes')->where('user_id', $user->id)->orderByDesc('id')->first();
        DB::table('account_closure_codes')->where('id', $row->id)->update(['code_hash' => hash_hmac('sha256', 'account-closure:'.$user->id.':123456', config('app.key'))]);
        \Carbon\Carbon::setTestNow($at->addMinutes(14)); \Carbon\CarbonImmutable::setTestNow($at->addMinutes(14));
        $input = $this->input(); unset($input['password']); $input['email_code'] = '123456';
        $this->postJson('/api/v2/me/account-closure', $input)->assertStatus(422);
        self::assertSame(0, DB::table('account_closures')->where('user_id', $user->id)->count());
    }
}
