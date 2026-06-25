<?php

namespace App\Domain\Auth\Services;

use App\Domain\Auth\DTO\SocialUserProfile;
use App\Models\ReferralSetting;
use App\Models\SocialAccount;
use App\Models\SocialLoginSession;
use App\Models\User;
use App\Models\UserProfile;
use App\Models\UserReferral;
use App\Models\Wallet;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class SocialAuthService
{
    public function handleCallback(SocialUserProfile $profile, string $deviceName): array
    {
        if (! $profile->emailVerified) {
            throw ValidationException::withMessages([
                'email' => ['Googleで確認済みのメールアドレスを取得できませんでした。'],
            ]);
        }

        $socialAccount = SocialAccount::query()
            ->where('provider', $profile->provider)
            ->where('provider_user_id', $profile->providerUserId)
            ->with(['user.wallet', 'user.profile'])
            ->first();

        if ($socialAccount) {
            $user = $socialAccount->user;

            if (! $user || $user->status !== 'active') {
                throw ValidationException::withMessages([
                    'email' => ['このアカウントではログインできません。'],
                ]);
            }

            $socialAccount->forceFill([
                'email' => $profile->email,
                'name' => $profile->name,
                'avatar_url' => $profile->avatarUrl,
                'last_login_at' => now(),
                'raw_profile' => $profile->raw,
            ])->save();

            return [
                'status' => 'authenticated',
                'user' => $user->refresh()->load(['wallet', 'profile']),
                'access_token' => $user->createToken($deviceName, ['user'])->plainTextToken,
                'next_step' => $user->sms_verified_at ? null : 'sms_verification',
            ];
        }

        $this->ensureVerifiedEmailIsAvailable($profile->email);

        $plainToken = Str::random(64);

        $session = DB::transaction(function () use ($profile, $plainToken): SocialLoginSession {
            SocialLoginSession::query()
                ->where('provider', $profile->provider)
                ->where('provider_user_id', $profile->providerUserId)
                ->where('status', 'pending')
                ->update(['status' => 'canceled']);

            return SocialLoginSession::query()->create([
                'provider' => $profile->provider,
                'provider_user_id' => $profile->providerUserId,
                'email' => $profile->email,
                'name' => $profile->name,
                'avatar_url' => $profile->avatarUrl,
                'status' => 'pending',
                'token_hash' => hash('sha256', $plainToken),
                'expires_at' => now()->addMinutes(30),
                'raw_profile' => $profile->raw,
            ]);
        });

        return [
            'status' => 'registration_required',
            'registration_token' => $plainToken,
            'session' => $session,
            'profile' => [
                'name' => $session->name,
                'email' => $session->email,
            ],
            'next_step' => 'referral_registration',
        ];
    }

    public function completeRegistration(string $registrationToken, ?string $referralCode, string $deviceName): array
    {
        return DB::transaction(function () use ($registrationToken, $referralCode, $deviceName): array {
            $session = SocialLoginSession::query()
                ->where('token_hash', hash('sha256', $registrationToken))
                ->lockForUpdate()
                ->first();

            if (! $session || ! $session->isPending()) {
                throw ValidationException::withMessages([
                    'registration_token' => ['Googleログインの登録セッションが無効、または期限切れです。'],
                ]);
            }

            $this->ensureVerifiedEmailIsAvailable($session->email);

            if (SocialAccount::query()->where('provider', $session->provider)->where('provider_user_id', $session->provider_user_id)->exists()) {
                throw ValidationException::withMessages([
                    'registration_token' => ['このGoogleアカウントはすでに登録済みです。'],
                ]);
            }

            $referrer = null;
            $normalizedReferralCode = $referralCode ? strtoupper(trim($referralCode)) : null;

            if ($normalizedReferralCode) {
                $referrer = User::query()
                    ->where('referral_code', $normalizedReferralCode)
                    ->lockForUpdate()
                    ->first();

                if (! $referrer) {
                    throw ValidationException::withMessages([
                        'referral_code' => ['紹介コードが見つかりません。'],
                    ]);
                }
            }

            $user = User::query()->create([
                'name' => $session->name ?: $session->email,
                'email' => $session->email,
                'email_verified_at' => now(),
                'referral_code' => User::generateReferralCode(),
                'password' => Hash::make(Str::random(64)),
                'status' => 'active',
            ]);

            UserProfile::query()->create([
                'user_id' => $user->id,
            ]);

            Wallet::query()->create([
                'user_id' => $user->id,
                'paid_balance' => 0,
                'free_balance' => 0,
            ]);

            SocialAccount::query()->create([
                'user_id' => $user->id,
                'provider' => $session->provider,
                'provider_user_id' => $session->provider_user_id,
                'email' => $session->email,
                'name' => $session->name,
                'avatar_url' => $session->avatar_url,
                'linked_at' => now(),
                'last_login_at' => now(),
                'raw_profile' => $session->raw_profile,
            ]);

            if ($referrer && (int) $referrer->id !== (int) $user->id) {
                $setting = ReferralSetting::current();

                UserReferral::query()->create([
                    'referrer_user_id' => $referrer->id,
                    'referred_user_id' => $user->id,
                    'referral_code' => $normalizedReferralCode,
                    'status' => 'pending',
                    'reward_point_amount' => $setting->is_active ? (int) $setting->reward_point_amount : 0,
                    'reward_expiration_days' => $setting->is_active ? $setting->reward_expiration_days : null,
                ]);
            }

            $session->forceFill([
                'status' => 'completed',
                'completed_at' => now(),
            ])->save();

            return [
                'status' => 'registered',
                'user' => $user->refresh()->load(['wallet', 'profile']),
                'access_token' => $user->createToken($deviceName, ['user'])->plainTextToken,
                'next_step' => 'sms_verification',
            ];
        });
    }

    private function ensureVerifiedEmailIsAvailable(string $email): void
    {
        $exists = User::query()
            ->whereRaw('LOWER(email) = LOWER(?)', [$email])
            ->whereNotNull('email_verified_at')
            ->whereIn('status', ['active', 'suspended'])
            ->exists();

        if ($exists) {
            throw ValidationException::withMessages([
                'email' => ['既に登録済みのメールアドレスです。'],
            ]);
        }
    }
}
