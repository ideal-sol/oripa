<?php

namespace App\Domain\Referral\Services;

use App\Domain\Audit\V2\Services\V2AuditLogService;
use App\Domain\Identity\Contracts\V2AdminAuthorizationContext;
use App\Domain\Identity\Enums\V2Permission;
use App\Domain\Identity\Exceptions\V2AuthenticationException;
use App\Domain\Identity\Services\V2AdminFreshMfaAuthorizer;
use App\Domain\Identity\Services\V2RateLimiter;
use App\Domain\Outbox\Services\V2OutboxService;
use App\Domain\Point\Exceptions\V2PointException;
use App\Domain\Point\Services\V2PointIdempotencyService;
use App\Domain\Referral\Exceptions\V2ReferralException;
use App\Models\V2\ReferralPointSetting;
use Illuminate\Support\Facades\DB;

final class V2ReferralPointSettingService
{
    public const MAX_REWARD_AMOUNT = 1_000_000;

    public function __construct(
        private readonly V2AdminFreshMfaAuthorizer $authorization,
        private readonly V2RateLimiter $rateLimiter,
        private readonly V2PointIdempotencyService $idempotency,
        private readonly V2AuditLogService $audit,
        private readonly V2OutboxService $outbox
    ) {
    }

    /** @return array<string, mixed> */
    public function read(V2AdminAuthorizationContext $context): array
    {
        $this->authorization->authorizePermission(
            $context,
            V2Permission::ReadReferralSettings,
            false,
            'referral.settings.read'
        );

        return $this->serialize($this->setting());
    }

    /** @return array{data: array<string, mixed>, idempotent_replay: bool} */
    public function update(
        V2AdminAuthorizationContext $context,
        string $idempotencyKey,
        array $input
    ): array {
        $admin = $this->authorization->authorizePermission(
            $context,
            V2Permission::ManageReferralSettings,
            true,
            'referral.settings.update'
        );
        try {
            $this->rateLimiter->assertSubject('critical_admin_mutation', $admin->public_id);
        } catch (V2AuthenticationException $exception) {
            $this->audit->record('referral.settings.rate_limited', [
                'request_id' => $context->requestId,
                'actor_type' => 'admin',
                'actor_public_id' => $admin->public_id,
                'actor_role' => $admin->role->value,
                'auth_realm' => 'admin',
                'session_correlation_hash' => $context->sessionCorrelationHash,
                'target_type' => 'referral_point_setting',
                'outcome' => 'failure',
                'reason_code' => strtolower($exception->errorCode),
            ]);
            throw $exception;
        }
        $payload = $this->validate($input);

        try {
            return DB::transaction(function () use (
                $context,
                $admin,
                $idempotencyKey,
                $payload
            ): array {
                $claim = $this->idempotency->claim(
                    'referral.settings.update',
                    'admin',
                    $admin->public_id,
                    $idempotencyKey,
                    $payload
                );
                if ($claim->replay) {
                    $response = $claim->record->response_data;
                    if (! is_array($response) || ! isset($response['data'])) {
                        throw $this->unavailable();
                    }

                    return ['data' => $response['data'], 'idempotent_replay' => true];
                }

                $setting = ReferralPointSetting::query()->whereKey(1)->lockForUpdate()->first();
                if (! $setting instanceof ReferralPointSetting) {
                    throw $this->unavailable();
                }
                if ((int) $setting->revision !== $payload['expected_revision']) {
                    throw new V2ReferralException(
                        'REFERRAL_SETTING_REVISION_CONFLICT',
                        409,
                        'The referral point setting was updated by another operation.'
                    );
                }
                $before = $this->auditValues($setting);
                $setting->forceFill([
                    'is_enabled' => $payload['is_enabled'],
                    'referrer_point_amount' => $payload['referrer_point_amount'],
                    'referred_user_point_amount' => $payload['referred_user_point_amount'],
                    'reward_expiration_days' => $payload['reward_expiration_days'],
                    'revision' => (int) $setting->revision + 1,
                    'updated_by_admin_id' => $admin->getKey(),
                    'updated_at' => now()->startOfSecond(),
                ])->save();
                $setting->refresh();
                $data = $this->serialize($setting);
                $this->audit->record('referral.settings.updated', [
                    'request_id' => $context->requestId,
                    'actor_type' => 'admin',
                    'actor_public_id' => $admin->public_id,
                    'actor_role' => $admin->role->value,
                    'auth_realm' => 'admin',
                    'session_correlation_hash' => $context->sessionCorrelationHash,
                    'target_type' => 'referral_point_setting',
                    'target_public_id' => $setting->public_id,
                    'before' => $before,
                    'after' => $this->auditValues($setting),
                    'outcome' => 'success',
                ]);
                $this->outbox->enqueue(
                    'referral-point-setting-updated',
                    'referral_point_setting',
                    $setting->public_id,
                    'referral.point_setting.updated',
                    ['revision' => (int) $setting->revision],
                    'referral-point-setting:'.$setting->public_id.':'.$setting->revision
                );
                $this->idempotency->complete(
                    $claim->record,
                    'referral_point_setting',
                    $setting->public_id,
                    ['data' => $data]
                );

                return ['data' => $data, 'idempotent_replay' => false];
            }, 3);
        } catch (V2PointException $exception) {
            throw new V2ReferralException(
                $exception->getMessage() === 'IDEMPOTENCY_KEY_REUSED'
                    ? 'IDEMPOTENCY_KEY_REUSED'
                    : 'IDEMPOTENCY_REQUEST_IN_PROGRESS',
                409,
                'The referral setting update conflicts with another request.'
            );
        }
    }

    public function setting(bool $lock = false): ReferralPointSetting
    {
        $query = ReferralPointSetting::query()->whereKey(1);
        if ($lock) {
            $query->lockForUpdate();
        }
        $setting = $query->first();
        if (! $setting instanceof ReferralPointSetting) {
            throw $this->unavailable();
        }

        return $setting;
    }

    /** @return array<string, int|bool> */
    private function validate(array $input): array
    {
        $keys = [
            'expected_revision',
            'is_enabled',
            'referrer_point_amount',
            'referred_user_point_amount',
            'reward_expiration_days',
        ];
        if (
            count($input) !== count($keys)
            || array_diff($keys, array_keys($input)) !== []
            || ! is_int($input['expected_revision'] ?? null)
            || ! is_bool($input['is_enabled'] ?? null)
            || ! is_int($input['referrer_point_amount'] ?? null)
            || ! is_int($input['referred_user_point_amount'] ?? null)
            || ! is_int($input['reward_expiration_days'] ?? null)
            || $input['expected_revision'] < 1
            || $input['referrer_point_amount'] < 0
            || $input['referrer_point_amount'] > self::MAX_REWARD_AMOUNT
            || $input['referred_user_point_amount'] < 0
            || $input['referred_user_point_amount'] > self::MAX_REWARD_AMOUNT
            || $input['reward_expiration_days'] < 1
            || $input['reward_expiration_days'] > 3650
        ) {
            throw new V2ReferralException(
                'REFERRAL_SETTING_INVALID',
                422,
                'The referral point setting is invalid.'
            );
        }

        return $input;
    }

    /** @return array<string, mixed> */
    private function serialize(ReferralPointSetting $setting): array
    {
        return [
            'id' => $setting->public_id,
            'is_enabled' => (bool) $setting->is_enabled,
            'referrer_point_amount' => (int) $setting->referrer_point_amount,
            'referred_user_point_amount' => (int) $setting->referred_user_point_amount,
            'reward_expiration_days' => (int) $setting->reward_expiration_days,
            'grant_condition' => 'referred_user_sms_verified',
            'grant_timing' => 'on_sms_verification_completion',
            'applies_to' => 'future_referrals_only',
            'revision' => (int) $setting->revision,
            'updated_at' => $setting->updated_at->toIso8601String(),
        ];
    }

    /** @return array<string, int|bool> */
    private function auditValues(ReferralPointSetting $setting): array
    {
        return [
            'is_enabled' => (bool) $setting->is_enabled,
            'referrer_point_amount' => (int) $setting->referrer_point_amount,
            'referred_user_point_amount' => (int) $setting->referred_user_point_amount,
            'reward_expiration_days' => (int) $setting->reward_expiration_days,
            'revision' => (int) $setting->revision,
        ];
    }

    private function unavailable(): V2ReferralException
    {
        return new V2ReferralException(
            'REFERRAL_SETTING_UNAVAILABLE',
            503,
            'The referral point setting is unavailable.',
            true
        );
    }
}
