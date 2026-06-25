<?php

namespace App\Domain\Notification\Services;

use App\Domain\Notification\Contracts\SmsSender;
use App\Domain\Notification\DTO\SmsMessage;
use App\Domain\Referral\Services\ReferralRewardService;
use App\Models\SmsVerificationCode;
use App\Models\User;
use App\Models\UserProfile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class SmsVerificationService
{
    public function __construct(
        private readonly SmsSender $smsSender,
        private readonly ReferralRewardService $referralRewardService,
    ) {
    }

    public function send(User $user, ?string $phoneNumber = null, string $purpose = 'registration'): SmsVerificationCode
    {
        $user->loadMissing('profile');
        $phoneNumber = $this->normalizePhoneNumber($phoneNumber ?: $user->profile?->phone_number);

        if ($phoneNumber === '') {
            throw ValidationException::withMessages([
                'phone_number' => ['SMS認証に使用する電話番号を入力してください。'],
            ]);
        }

        $code = $this->generateNumericCode();
        $verification = DB::transaction(function () use ($user, $phoneNumber, $purpose, $code): SmsVerificationCode {
            $this->ensurePhoneNumberIsAvailable($user, $phoneNumber);

            SmsVerificationCode::query()
                ->where('user_id', $user->id)
                ->where('purpose', $purpose)
                ->where('status', 'pending')
                ->update(['status' => 'canceled']);

            $profile = $user->profile()->firstOrCreate(['user_id' => $user->id]);

            if ($profile->normalized_phone_number !== $phoneNumber) {
                $profile->forceFill([
                    'phone_number' => $phoneNumber,
                    'normalized_phone_number' => $phoneNumber,
                ])->save();
                $user->forceFill(['sms_verified_at' => null])->save();
            }

            return SmsVerificationCode::query()->create([
                'user_id' => $user->id,
                'phone_number' => $phoneNumber,
                'purpose' => $purpose,
                'status' => 'pending',
                'code_hash' => Hash::make($code),
                'max_attempts' => (int) config('services.sms.verification_code_max_attempts', 5),
                'last_sent_at' => now(),
                'expires_at' => now()->addMinutes((int) config('services.sms.verification_code_ttl_minutes', 10)),
            ]);
        });

        $result = $this->smsSender->send(new SmsMessage(
            to: $phoneNumber,
            body: "Luxe PackのSMS認証コードは{$code}です。",
            userId: $user->id,
            purpose: $purpose,
            metadata: ['sms_verification_code_id' => $verification->id],
        ));

        $verification->forceFill([
            'metadata' => [
                'provider' => $result->provider,
                'message_id' => $result->messageId,
                'sent' => $result->sent,
                'reason' => $result->reason,
            ],
        ])->save();

        return $verification->refresh();
    }

    public function verify(User $user, string $code, string $purpose = 'registration'): User
    {
        $result = DB::transaction(function () use ($user, $code, $purpose): User|ValidationException {
            $verification = SmsVerificationCode::query()
                ->where('user_id', $user->id)
                ->where('purpose', $purpose)
                ->where('status', 'pending')
                ->latest('id')
                ->lockForUpdate()
                ->first();

            if (! $verification) {
                return ValidationException::withMessages([
                    'code' => ['有効なSMS認証コードがありません。'],
                ]);
            }

            if ($verification->expires_at->isPast()) {
                $verification->forceFill(['status' => 'expired'])->save();

                return ValidationException::withMessages([
                    'code' => ['SMS認証コードの有効期限が切れています。'],
                ]);
            }

            if ($verification->attempts >= $verification->max_attempts) {
                $verification->forceFill(['status' => 'canceled'])->save();

                return ValidationException::withMessages([
                    'code' => ['SMS認証コードの試行回数を超えています。'],
                ]);
            }

            if (! Hash::check($code, $verification->code_hash)) {
                $attempts = $verification->attempts + 1;
                $verification->forceFill([
                    'attempts' => $attempts,
                    'status' => $attempts >= $verification->max_attempts ? 'canceled' : 'pending',
                ])->save();

                return ValidationException::withMessages([
                    'code' => ['SMS認証コードが正しくありません。'],
                ]);
            }

            $phoneConflict = $this->phoneNumberOwner($user, $verification->phone_number);

            if ($phoneConflict) {
                $verification->forceFill(['status' => 'canceled'])->save();

                return ValidationException::withMessages([
                    'phone_number' => ['この電話番号はすでにSMS認証済みです。'],
                ]);
            }

            $profile = $user->profile()->firstOrCreate(['user_id' => $user->id]);
            $profile->forceFill([
                'phone_number' => $verification->phone_number,
                'normalized_phone_number' => $verification->phone_number,
            ])->save();

            $verification->forceFill([
                'status' => 'verified',
                'verified_at' => now(),
            ])->save();

            $user->forceFill([
                'sms_verified_at' => now(),
            ])->save();

            $this->referralRewardService->rewardForReferredUser($user);

            return $user->refresh()->load(['wallet', 'profile']);
        });

        if ($result instanceof ValidationException) {
            throw $result;
        }

        return $result;
    }

    public function status(User $user): array
    {
        $user->loadMissing('profile');

        $pending = SmsVerificationCode::query()
            ->where('user_id', $user->id)
            ->where('purpose', 'registration')
            ->where('status', 'pending')
            ->latest('id')
            ->first();

        return [
            'sms_verified' => $user->sms_verified_at !== null,
            'sms_verified_at' => $user->sms_verified_at?->toIso8601String(),
            'pending' => $pending?->isPending() ?? false,
            'phone_number' => $pending?->phone_number ?? $user->profile?->phone_number,
            'expires_at' => $pending?->expires_at?->toIso8601String(),
            'attempts' => $pending?->attempts,
            'max_attempts' => $pending?->max_attempts,
        ];
    }

    private function generateNumericCode(): string
    {
        return str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    }

    private function normalizePhoneNumber(?string $phoneNumber): string
    {
        return UserProfile::normalizePhoneNumber($phoneNumber) ?? '';
    }

    private function ensurePhoneNumberIsAvailable(User $user, string $phoneNumber): void
    {
        if ($this->phoneNumberOwner($user, $phoneNumber)) {
            throw ValidationException::withMessages([
                'phone_number' => ['この電話番号はすでにSMS認証済みです。'],
            ]);
        }
    }

    private function phoneNumberOwner(User $user, string $phoneNumber): ?UserProfile
    {
        return UserProfile::query()
            ->where('normalized_phone_number', $phoneNumber)
            ->where('user_id', '!=', $user->id)
            ->whereHas('user', function ($query): void {
                $query
                    ->whereNotNull('sms_verified_at')
                    ->whereIn('status', ['active', 'suspended']);
            })
            ->lockForUpdate()
            ->first();
    }
}
