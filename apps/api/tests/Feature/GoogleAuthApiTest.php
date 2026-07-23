<?php

namespace Tests\Feature;

use App\Models\ReferralSetting;
use App\Models\SocialAccount;
use App\Models\SocialLoginSession;
use App\Models\User;
use App\Models\UserReferral;
use App\Models\Wallet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class GoogleAuthApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.google.client_id' => 'google-client-id',
            'services.google.client_secret' => 'google-client-secret',
            'services.google.redirect_uri' => 'https://luxe-pack.biz/auth/google/callback',
        ]);
    }

    public function test_google_redirect_returns_authorization_url_and_state(): void
    {
        $this->getJson('/api/auth/google/redirect')
            ->assertOk()
            ->assertJsonPath('authorization_url', fn (string $url): bool => str_contains($url, 'https://accounts.google.com/o/oauth2/v2/auth'))
            ->assertJsonPath('state', fn (string $state): bool => strlen($state) === 48);
    }

    public function test_google_callback_returns_registration_session_for_new_email(): void
    {
        $state = $this->putGoogleState();
        $this->fakeGoogleProfile([
            'sub' => 'google-user-1',
            'email' => 'google-new@example.test',
            'email_verified' => true,
            'name' => 'Google New',
        ]);

        $this->postJson('/api/auth/google/callback', [
            'code' => 'valid-code',
            'state' => $state,
            'device_name' => 'feature-test',
        ])
            ->assertOk()
            ->assertJsonPath('status', 'registration_required')
            ->assertJsonPath('profile.email', 'google-new@example.test')
            ->assertJsonPath('profile.name', 'Google New')
            ->assertJsonPath('next_step', 'referral_registration')
            ->assertJsonPath('registration_token', fn (string $token): bool => strlen($token) === 64);

        $this->assertSame(1, SocialLoginSession::query()->where('email', 'google-new@example.test')->where('status', 'pending')->count());
        $this->assertSame(0, User::query()->where('email', 'google-new@example.test')->count());
    }

    public function test_google_callback_rejects_existing_verified_email(): void
    {
        User::factory()->create([
            'email' => 'registered@example.test',
            'email_verified_at' => now(),
            'status' => 'active',
        ]);
        $state = $this->putGoogleState();
        $this->fakeGoogleProfile([
            'sub' => 'google-user-2',
            'email' => 'registered@example.test',
            'email_verified' => true,
            'name' => 'Registered',
        ]);

        $this->postJson('/api/auth/google/callback', [
            'code' => 'valid-code',
            'state' => $state,
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['email'])
            ->assertJsonPath('errors.email.0', '既に登録済みのメールアドレスです。');
    }

    public function test_google_registration_allows_duplicate_unverified_email_and_invalidates_old_link(): void
    {
        $unverified = User::factory()->create([
            'email' => 'duplicate-unverified@example.test',
            'email_verified_at' => null,
            'status' => 'active',
        ]);
        $verificationUrl = URL::temporarySignedRoute(
            'api.email.verify',
            now()->addHours(24),
            [
                'user' => $unverified->id,
                'hash' => sha1($unverified->email),
            ],
        );
        $registrationToken = $this->createPendingGoogleRegistrationSession(
            email: 'duplicate-unverified@example.test',
            providerUserId: 'google-user-3',
            name: 'Google Duplicate',
        );

        $this->postJson('/api/auth/google/register', [
            'registration_token' => $registrationToken,
            'device_name' => 'feature-test',
        ])
            ->assertCreated()
            ->assertJsonPath('status', 'registered')
            ->assertJsonPath('user.email', 'duplicate-unverified@example.test')
            ->assertJsonPath('user.email_verified_at', fn (?string $value): bool => $value !== null)
            ->assertJsonPath('user.sms_verified', false)
            ->assertJsonPath('next_step', 'sms_verification');

        $this->assertSame(2, User::query()->where('email', 'duplicate-unverified@example.test')->count());
        $this->assertSame(1, User::query()->where('email', 'duplicate-unverified@example.test')->whereNotNull('email_verified_at')->count());
        $this->assertSame(1, SocialAccount::query()->where('provider', 'google')->where('provider_user_id', 'google-user-3')->count());

        $this->getJson($verificationUrl)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['email']);
    }

    public function test_google_registration_creates_pending_referral(): void
    {
        $referrer = User::factory()->create(['referral_code' => 'LPGOOGLE001']);
        ReferralSetting::query()->updateOrCreate(
            ['id' => 1],
            [
                'reward_point_amount' => 900,
                'reward_expiration_days' => 180,
                'is_active' => true,
            ],
        );
        $registrationToken = $this->createPendingGoogleRegistrationSession(
            email: 'google-referred@example.test',
            providerUserId: 'google-user-4',
            name: 'Google Referred',
        );

        $this->postJson('/api/auth/google/register', [
            'registration_token' => $registrationToken,
            'referral_code' => 'lpgoogle001',
        ])
            ->assertCreated()
            ->assertJsonPath('user.email', 'google-referred@example.test');

        $referred = User::query()->where('email', 'google-referred@example.test')->whereNotNull('email_verified_at')->firstOrFail();

        $this->assertDatabaseHas('user_referrals', [
            'referrer_user_id' => $referrer->id,
            'referred_user_id' => $referred->id,
            'referral_code' => 'LPGOOGLE001',
            'status' => 'pending',
            'reward_point_amount' => 900,
        ]);
        $this->assertSame(1, UserReferral::query()->count());
    }

    public function test_linked_google_account_can_login(): void
    {
        $user = User::factory()->create([
            'email' => 'linked@example.test',
            'email_verified_at' => now(),
            'status' => 'active',
        ]);
        Wallet::query()->create([
            'user_id' => $user->id,
            'paid_balance' => 0,
            'free_balance' => 0,
        ]);
        $user->profile()->create([]);
        SocialAccount::query()->create([
            'user_id' => $user->id,
            'provider' => 'google',
            'provider_user_id' => 'google-linked-1',
            'email' => 'linked@example.test',
            'name' => 'Linked User',
            'linked_at' => now(),
        ]);
        $state = $this->putGoogleState();
        $this->fakeGoogleProfile([
            'sub' => 'google-linked-1',
            'email' => 'linked@example.test',
            'email_verified' => true,
            'name' => 'Linked User Updated',
        ]);

        $this->postJson('/api/auth/google/callback', [
            'code' => 'valid-code',
            'state' => $state,
        ])
            ->assertOk()
            ->assertJsonPath('status', 'authenticated')
            ->assertJsonPath('token_type', 'Bearer')
            ->assertJsonPath('user.id', $user->id)
            ->assertJsonPath('next_step', 'sms_verification')
            ->assertJsonPath('access_token', fn (string $token): bool => $token !== '');

        $this->assertNotNull(SocialAccount::query()->where('provider_user_id', 'google-linked-1')->firstOrFail()->last_login_at);
    }

    private function putGoogleState(): string
    {
        $state = 'test-google-state-'.str()->random(12);
        Cache::put("oauth:google:state:{$state}", true, now()->addMinutes(10));

        return $state;
    }

    private function fakeGoogleProfile(array $profile): void
    {
        Http::fake([
            'https://oauth2.googleapis.com/token' => Http::response([
                'access_token' => 'fake-google-access-token',
                'token_type' => 'Bearer',
                'expires_in' => 3600,
            ], 200),
            'https://www.googleapis.com/oauth2/v3/userinfo' => Http::response($profile, 200),
        ]);
    }

    private function createPendingGoogleRegistrationSession(string $email, string $providerUserId, string $name): string
    {
        $plainToken = str()->random(64);
        SocialLoginSession::query()->create([
            'provider' => 'google',
            'provider_user_id' => $providerUserId,
            'email' => $email,
            'name' => $name,
            'status' => 'pending',
            'token_hash' => hash('sha256', $plainToken),
            'expires_at' => now()->addMinutes(30),
            'raw_profile' => [
                'sub' => $providerUserId,
                'email' => $email,
                'email_verified' => true,
                'name' => $name,
            ],
        ]);

        return $plainToken;
    }
}
