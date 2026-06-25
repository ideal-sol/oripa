<?php

namespace Tests\Feature;

use App\Models\User;
use App\Mail\PasswordResetMail;
use App\Mail\UserEmailVerificationMail;
use App\Models\ReferralSetting;
use App\Models\Wallet;
use App\Models\UserReferral;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class UserAuthApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_register_and_receive_email_verification_mail(): void
    {
        Mail::fake();
        config(['app.frontend_url' => 'https://luxe-pack.biz']);

        $this->postJson('/api/register', [
            'name' => 'New User',
            'email' => 'new-user@example.test',
            'password' => 'secret-password',
            'password_confirmation' => 'secret-password',
            'last_name' => '山田',
            'first_name' => '太郎',
            'phone_number' => '09012345678',
            'device_name' => 'feature-test',
        ])
            ->assertCreated()
            ->assertJsonPath('message', 'Registration has been accepted. Please verify your email address within 24 hours.')
            ->assertJsonPath('user.email', 'new-user@example.test')
            ->assertJsonPath('user.wallet.total_balance', 0)
            ->assertJsonPath('user.profile.last_name', '山田')
            ->assertJsonPath('user.email_verified_at', null);

        $this->assertDatabaseHas('users', [
            'email' => 'new-user@example.test',
            'status' => 'active',
            'email_verified_at' => null,
        ]);
        $this->assertDatabaseHas('user_profiles', [
            'last_name' => '山田',
            'first_name' => '太郎',
        ]);
        $this->assertDatabaseHas('wallets', [
            'paid_balance' => 0,
            'free_balance' => 0,
        ]);
        $user = User::query()->where('email', 'new-user@example.test')->firstOrFail();
        $this->assertSame(0, $user->tokens()->count());
        Mail::assertSent(
            UserEmailVerificationMail::class,
            fn (UserEmailVerificationMail $mail): bool => $mail->hasTo('new-user@example.test')
                && str_contains($mail->render(), 'https://luxe-pack.biz/email/verify?token=')
                && ! str_contains($mail->render(), '/api/email/verify'),
        );
    }

    public function test_register_payload_is_validated(): void
    {
        User::factory()->create(['email' => 'used@example.test']);

        $this->postJson('/api/register', [
            'name' => '',
            'email' => 'used@example.test',
            'password' => 'short',
            'password_confirmation' => 'different',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['name', 'email', 'password']);
    }

    public function test_register_rejects_plus_alias_email_local_part(): void
    {
        $this->postJson('/api/register', [
            'name' => 'Alias User',
            'email' => 'alias+test@example.test',
            'password' => 'secret-password',
            'password_confirmation' => 'secret-password',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['email']);
    }

    public function test_register_allows_duplicate_unverified_email(): void
    {
        Mail::fake();
        User::factory()->create([
            'email' => 'duplicate-unverified@example.test',
            'email_verified_at' => null,
            'status' => 'active',
        ]);

        $this->postJson('/api/register', [
            'name' => 'Duplicate Unverified',
            'email' => 'duplicate-unverified@example.test',
            'password' => 'secret-password',
            'password_confirmation' => 'secret-password',
        ])
            ->assertCreated()
            ->assertJsonPath('user.email', 'duplicate-unverified@example.test');

        $this->assertSame(2, User::query()->where('email', 'duplicate-unverified@example.test')->count());
    }

    public function test_register_rejects_duplicate_verified_email(): void
    {
        User::factory()->create([
            'email' => 'duplicate-verified@example.test',
            'email_verified_at' => now(),
            'status' => 'active',
        ]);

        $this->postJson('/api/register', [
            'name' => 'Duplicate Verified',
            'email' => 'duplicate-verified@example.test',
            'password' => 'secret-password',
            'password_confirmation' => 'secret-password',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['email']);
    }

    public function test_register_allows_withdrawn_verified_email_reuse(): void
    {
        Mail::fake();
        User::factory()->create([
            'email' => 'withdrawn-email@example.test',
            'email_verified_at' => now(),
            'status' => 'withdrawn',
        ]);

        $this->postJson('/api/register', [
            'name' => 'Reuse Withdrawn',
            'email' => 'withdrawn-email@example.test',
            'password' => 'secret-password',
            'password_confirmation' => 'secret-password',
        ])
            ->assertCreated()
            ->assertJsonPath('user.email', 'withdrawn-email@example.test');
    }

    public function test_register_with_referral_code_creates_pending_referral(): void
    {
        Mail::fake();
        $referrer = User::factory()->create([
            'referral_code' => 'LPREFERRAL1',
        ]);
        ReferralSetting::query()->updateOrCreate(
            ['id' => 1],
            [
                'reward_point_amount' => 700,
                'reward_expiration_days' => 180,
                'is_active' => true,
            ],
        );

        $this->postJson('/api/register', [
            'name' => 'Referred User',
            'email' => 'referred-user@example.test',
            'password' => 'secret-password',
            'password_confirmation' => 'secret-password',
            'referral_code' => 'lpreferral1',
        ])
            ->assertCreated()
            ->assertJsonPath('user.email', 'referred-user@example.test')
            ->assertJsonPath('user.referral_code', fn (?string $value): bool => is_string($value) && str_starts_with($value, 'LP'));

        $referred = User::query()->where('email', 'referred-user@example.test')->firstOrFail();

        $this->assertDatabaseHas('user_referrals', [
            'referrer_user_id' => $referrer->id,
            'referred_user_id' => $referred->id,
            'referral_code' => 'LPREFERRAL1',
            'status' => 'pending',
            'reward_point_amount' => 700,
            'reward_expiration_days' => 180,
            'rewarded_at' => null,
        ]);
        $this->assertSame(1, UserReferral::query()->count());
    }

    public function test_register_rejects_unknown_referral_code(): void
    {
        $this->postJson('/api/register', [
            'name' => 'Unknown Referral User',
            'email' => 'unknown-referral@example.test',
            'password' => 'secret-password',
            'password_confirmation' => 'secret-password',
            'referral_code' => 'LPUNKNOWN',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['referral_code']);
    }

    public function test_active_user_can_login_and_receive_token(): void
    {
        User::factory()->create([
            'email' => 'user@example.test',
            'email_verified_at' => null,
            'password' => Hash::make('other-password'),
            'status' => 'active',
        ]);
        $user = User::factory()->create([
            'email' => 'user@example.test',
            'email_verified_at' => now(),
            'password' => Hash::make('secret-password'),
            'status' => 'active',
        ]);
        Wallet::query()->create([
            'user_id' => $user->id,
            'paid_balance' => 1000,
            'free_balance' => 200,
        ]);

        $this->postJson('/api/login', [
            'email' => 'user@example.test',
            'password' => 'secret-password',
            'device_name' => 'feature-test',
        ])
            ->assertOk()
            ->assertJsonPath('token_type', 'Bearer')
            ->assertJsonPath('user.id', $user->id)
            ->assertJsonPath('user.email', 'user@example.test')
            ->assertJsonPath('user.wallet.total_balance', 1200)
            ->assertJsonStructure(['access_token']);

        $this->assertSame(1, $user->tokens()->count());
    }

    public function test_unverified_user_cannot_login(): void
    {
        User::factory()->create([
            'email' => 'unverified@example.test',
            'email_verified_at' => null,
            'password' => Hash::make('secret-password'),
            'status' => 'active',
        ]);

        $this->postJson('/api/login', [
            'email' => 'unverified@example.test',
            'password' => 'secret-password',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['email']);
    }

    public function test_login_rejects_invalid_or_inactive_user(): void
    {
        User::factory()->create([
            'email' => 'inactive@example.test',
            'password' => Hash::make('secret-password'),
            'status' => 'suspended',
        ]);

        $this->postJson('/api/login', [
            'email' => 'inactive@example.test',
            'password' => 'secret-password',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['email']);

        $this->postJson('/api/login', [
            'email' => 'inactive@example.test',
            'password' => 'wrong-password',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['email']);

        $this->assertDatabaseMissing('personal_access_tokens', [
            'tokenable_type' => User::class,
            'name' => 'feature-test',
        ]);
    }

    public function test_user_can_logout_current_token(): void
    {
        $user = User::factory()->create(['status' => 'active']);
        $token = $user->createToken('feature-test', ['user']);

        $this->withHeader('Authorization', 'Bearer '.$token->plainTextToken)
            ->postJson('/api/logout')
            ->assertOk()
            ->assertJsonPath('message', 'Logged out.');

        $this->assertSame(0, $user->tokens()->count());
    }

    public function test_user_can_verify_email_with_signed_url(): void
    {
        $user = User::factory()->create([
            'email' => 'verify@example.test',
            'email_verified_at' => null,
        ]);

        $url = URL::temporarySignedRoute(
            'api.email.verify',
            now()->addHours(24),
            [
                'user' => $user->id,
                'hash' => sha1($user->email),
            ],
        );

        $this->getJson($url)
            ->assertOk()
            ->assertJsonPath('message', 'Email has been verified.')
            ->assertJsonPath('user.email_verified_at', fn (?string $value): bool => $value !== null);

        $this->assertNotNull($user->refresh()->email_verified_at);
    }

    public function test_verified_duplicate_email_invalidates_other_unverified_email_links(): void
    {
        $first = User::factory()->create([
            'email' => 'same-email@example.test',
            'email_verified_at' => null,
        ]);
        $second = User::factory()->create([
            'email' => 'same-email@example.test',
            'email_verified_at' => null,
        ]);

        $firstUrl = URL::temporarySignedRoute(
            'api.email.verify',
            now()->addHours(24),
            [
                'user' => $first->id,
                'hash' => sha1($first->email),
            ],
        );
        $secondUrl = URL::temporarySignedRoute(
            'api.email.verify',
            now()->addHours(24),
            [
                'user' => $second->id,
                'hash' => sha1($second->email),
            ],
        );

        $this->getJson($firstUrl)->assertOk();
        $this->getJson($secondUrl)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['email']);

        $this->assertNotNull($first->refresh()->email_verified_at);
        $this->assertNull($second->refresh()->email_verified_at);
    }

    public function test_expired_email_verification_link_is_rejected(): void
    {
        $user = User::factory()->create([
            'email' => 'expired@example.test',
            'email_verified_at' => null,
        ]);

        $url = URL::temporarySignedRoute(
            'api.email.verify',
            now()->subMinute(),
            [
                'user' => $user->id,
                'hash' => sha1($user->email),
            ],
        );

        $this->getJson($url)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['email']);

        $this->assertNull($user->refresh()->email_verified_at);
    }

    public function test_user_can_request_email_verification_resend(): void
    {
        Mail::fake();

        User::factory()->create([
            'email' => 'resend@example.test',
            'email_verified_at' => null,
            'status' => 'active',
        ]);

        $this->postJson('/api/email/verification-notification', [
            'email' => 'resend@example.test',
        ])
            ->assertOk()
            ->assertJsonPath('message', 'If the email exists and is not verified, a verification link has been sent.');

        Mail::assertSent(UserEmailVerificationMail::class, fn (UserEmailVerificationMail $mail): bool => $mail->hasTo('resend@example.test'));
    }

    public function test_user_can_request_password_reset_mail(): void
    {
        Mail::fake();

        User::factory()->create([
            'email' => 'reset@example.test',
            'status' => 'active',
        ]);

        $this->postJson('/api/password/forgot', [
            'email' => 'reset@example.test',
        ])
            ->assertOk()
            ->assertJsonPath('message', 'If the email exists, a password reset link has been sent.');

        Mail::assertSent(PasswordResetMail::class);
        $this->assertDatabaseHas('password_reset_tokens', [
            'email' => 'reset@example.test',
        ]);
    }

    public function test_password_reset_updates_password_and_revokes_tokens(): void
    {
        User::factory()->create([
            'email' => 'resettable@example.test',
            'email_verified_at' => null,
            'password' => Hash::make('duplicate-old-password'),
            'status' => 'active',
        ]);
        $user = User::factory()->create([
            'email' => 'resettable@example.test',
            'email_verified_at' => now(),
            'password' => Hash::make('old-password'),
            'status' => 'active',
        ]);
        $user->createToken('feature-test', ['user']);
        $token = Password::broker()->createToken($user);

        $this->postJson('/api/password/reset', [
            'email' => 'resettable@example.test',
            'token' => $token,
            'password' => 'new-secret-password',
            'password_confirmation' => 'new-secret-password',
        ])
            ->assertOk()
            ->assertJsonPath('message', 'Password has been reset.');

        $user->refresh();
        $this->assertTrue(Hash::check('new-secret-password', $user->password));
        $this->assertSame(0, $user->tokens()->count());
    }
}
