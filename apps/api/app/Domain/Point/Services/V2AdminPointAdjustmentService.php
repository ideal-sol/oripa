<?php

namespace App\Domain\Point\Services;

use App\Domain\Audit\V2\Services\V2AuditLogService;
use App\Domain\Identity\Contracts\V2AdminAuthorizationContext;
use App\Domain\Identity\Enums\V2AdminState;
use App\Domain\Identity\Enums\V2Permission;
use App\Domain\Identity\Exceptions\V2AuthenticationException;
use App\Domain\Identity\Services\V2AdminFreshMfaAuthorizer;
use App\Domain\Identity\Services\V2PasswordPolicy;
use App\Domain\Identity\Services\V2PermissionAuthorizer;
use App\Domain\Point\Exceptions\V2AdminPointAdjustmentException;
use App\Domain\Point\Exceptions\V2PointException;
use App\Models\V2\Admin;
use App\Models\V2\PointAdjustment;
use App\Models\V2\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Normalizer;
use SensitiveParameter;

final class V2AdminPointAdjustmentService
{
    public function __construct(
        private readonly V2AdminFreshMfaAuthorizer $authorization,
        private readonly V2PermissionAuthorizer $permissions,
        private readonly V2PasswordPolicy $passwords,
        private readonly V2PointTransactionRunner $transactions,
        private readonly V2PointIdempotencyService $idempotency,
        private readonly V2PointService $points,
        private readonly V2AuditLogService $audit
    ) {
    }

    /**
     * @param array<string, mixed> $input
     * @return array{data: array<string, mixed>, idempotent_replay: bool}
     */
    public function execute(
        V2AdminAuthorizationContext $context,
        string $userPublicId,
        string $idempotencyKey,
        array $input,
        #[SensitiveParameter] string $currentPassword
    ): array {
        $admin = $this->authorization->authorizePermission(
            $context,
            V2Permission::ManagePointAdjustment,
            true,
            'point.admin_adjustment.execute',
            true
        );
        if (! $this->passwords->verify($currentPassword, $admin->password_hash)) {
            throw new V2AuthenticationException(
                'INVALID_CURRENT_PASSWORD',
                401,
                'The current password could not be verified.'
            );
        }
        if (! Str::isUuid($userPublicId)) {
            throw $this->invalid();
        }
        $request = $this->validate($input);

        try {
            return $this->transactions->run(function () use (
                $context,
                $admin,
                $userPublicId,
                $idempotencyKey,
                $request,
                $currentPassword
            ): array {
                $lockedAdmin = Admin::query()->whereKey($admin->getKey())->lockForUpdate()->first();
                if (
                    ! $lockedAdmin instanceof Admin
                    || $lockedAdmin->state !== V2AdminState::Active
                    || ! $this->permissions->allows(
                        $lockedAdmin->role,
                        V2Permission::ManagePointAdjustment
                    )
                ) {
                    throw new V2AuthenticationException(
                        'AUTHORIZATION_DENIED',
                        403,
                        'The point adjustment is not permitted.'
                    );
                }
                if (! $this->passwords->verify($currentPassword, $lockedAdmin->password_hash)) {
                    throw new V2AuthenticationException(
                        'INVALID_CURRENT_PASSWORD',
                        401,
                        'The current password could not be verified.'
                    );
                }
                $user = User::query()->where('public_id', $userPublicId)->lockForUpdate()->first();
                if (! $user instanceof User) {
                    throw new V2AdminPointAdjustmentException(
                        'ADMIN_USER_NOT_FOUND',
                        404,
                        'The user was not found.'
                    );
                }
                $claim = $this->idempotency->claim(
                    'point.admin_adjustment',
                    'admin',
                    $lockedAdmin->public_id,
                    $idempotencyKey,
                    ['user_public_id' => $userPublicId, ...$request]
                );
                if ($claim->replay) {
                    $response = $claim->record->response_data;
                    if (! is_array($response) || ! is_array($response['data'] ?? null)) {
                        throw new V2AdminPointAdjustmentException(
                            'POINT_ADJUSTMENT_UNAVAILABLE',
                            503,
                            'The canonical point adjustment result is unavailable.',
                            true
                        );
                    }

                    return ['data' => $response['data'], 'idempotent_replay' => true];
                }

                $result = $this->points->applyAdminAdjustmentWithinTransaction(
                    (int) $user->getKey(),
                    (int) $lockedAdmin->getKey(),
                    $request['point_type'],
                    $request['direction'],
                    $request['amount'],
                    'point.admin_adjustment:'.$claim->record->key_hash,
                    CarbonImmutable::now()
                );
                $operation = $result['operation'];
                $adjustment = new PointAdjustment();
                $adjustment->forceFill([
                    'user_id' => $user->getKey(),
                    'direction' => $request['direction'],
                    'point_type' => $request['point_type'],
                    'amount' => $request['amount'],
                    'expire_at' => $result['expire_at'],
                    'reason_code' => 'manual_admin_adjustment',
                    'reason_text' => $request['reason'],
                    'status' => 'executed',
                    'requested_by_admin_id' => $lockedAdmin->getKey(),
                    'approved_by_admin_id' => $lockedAdmin->getKey(),
                    'point_operation_id' => $operation->getKey(),
                    'requested_at' => $result['occurred_at'],
                    'executed_at' => $result['occurred_at'],
                ])->save();
                $data = $this->serialize($adjustment, $user, $operation->public_id, $result);
                $this->audit->record('point.admin_adjusted', [
                    'request_id' => $context->requestId,
                    'actor_type' => 'admin',
                    'actor_public_id' => $lockedAdmin->public_id,
                    'actor_role' => $lockedAdmin->role->value,
                    'auth_realm' => 'admin',
                    'session_correlation_hash' => $context->sessionCorrelationHash,
                    'target_type' => 'user_wallet',
                    'target_public_id' => $user->public_id,
                    'reason_text' => $request['reason'],
                    'before' => [
                        'paid_balance' => $result['paid_before'],
                        'free_balance' => $result['free_before'],
                    ],
                    'after' => [
                        'paid_balance' => $result['paid_after'],
                        'free_balance' => $result['free_after'],
                    ],
                    'metadata' => [
                        'adjustment_public_id' => $adjustment->public_id,
                        'operation_public_id' => $operation->public_id,
                        'point_type' => $request['point_type'],
                        'direction' => $request['direction'],
                        'amount' => $request['amount'],
                        'request_key_hash' => $claim->record->key_hash,
                    ],
                ]);
                $this->idempotency->complete(
                    $claim->record,
                    'point_adjustment',
                    $adjustment->public_id,
                    ['data' => $data]
                );

                return ['data' => $data, 'idempotent_replay' => false];
            });
        } catch (V2PointException $exception) {
            throw match ($exception->getMessage()) {
                'INSUFFICIENT_POINT_BALANCE' => new V2AdminPointAdjustmentException(
                    'POINT_ADJUSTMENT_INSUFFICIENT_BALANCE',
                    409,
                    'The selected point balance is insufficient.'
                ),
                'IDEMPOTENCY_KEY_REUSED' => new V2AdminPointAdjustmentException(
                    'IDEMPOTENCY_KEY_REUSED',
                    409,
                    'The idempotency key was already used for another request.'
                ),
                'IDEMPOTENCY_REQUEST_IN_PROGRESS' => new V2AdminPointAdjustmentException(
                    'IDEMPOTENCY_REQUEST_IN_PROGRESS',
                    409,
                    'The point adjustment request is already in progress.',
                    true
                ),
                default => new V2AdminPointAdjustmentException(
                    'POINT_ADJUSTMENT_INVALID',
                    422,
                    'The point adjustment could not be completed.'
                ),
            };
        }
    }

    /** @param array<string, mixed> $input @return array{point_type: string, direction: string, amount: int, reason: string} */
    private function validate(array $input): array
    {
        if (array_diff(array_keys($input), ['point_type', 'direction', 'amount', 'reason']) !== []) {
            throw $this->invalid();
        }
        $pointType = $input['point_type'] ?? null;
        $direction = $input['direction'] ?? null;
        $amount = $input['amount'] ?? null;
        $reason = $input['reason'] ?? null;
        if (
            ! is_string($pointType)
            || ! in_array($pointType, ['paid', 'free'], true)
            || ! is_string($direction)
            || ! in_array($direction, ['grant', 'deduct'], true)
            || ! is_int($amount)
            || $amount < 1
            || ! is_string($reason)
        ) {
            throw $this->invalid();
        }
        $normalized = Normalizer::normalize($reason, Normalizer::FORM_C);
        if (! is_string($normalized)) {
            throw $this->invalid();
        }
        $normalized = trim($normalized);
        if (
            $normalized === ''
            || strlen($normalized) > 500
            || preg_match('/[\x00-\x1F\x7F]|[<>]/u', $normalized) === 1
        ) {
            throw $this->invalid();
        }

        return [
            'point_type' => $pointType,
            'direction' => $direction,
            'amount' => $amount,
            'reason' => $normalized,
        ];
    }

    /**
     * @param array<string, mixed> $result
     * @return array<string, mixed>
     */
    private function serialize(
        PointAdjustment $adjustment,
        User $user,
        string $operationPublicId,
        array $result
    ): array {
        return [
            'adjustment_public_id' => $adjustment->public_id,
            'user_public_id' => $user->public_id,
            'operation_public_id' => $operationPublicId,
            'point_type' => $adjustment->point_type,
            'direction' => $adjustment->direction,
            'amount' => (int) $adjustment->amount,
            'reason' => $adjustment->reason_text,
            'paid_balance_before' => $result['paid_before'],
            'paid_balance_after' => $result['paid_after'],
            'free_balance_before' => $result['free_before'],
            'free_balance_after' => $result['free_after'],
            'executed_at' => $adjustment->executed_at?->utc()->toIso8601String(),
        ];
    }

    private function invalid(): V2AdminPointAdjustmentException
    {
        return new V2AdminPointAdjustmentException(
            'POINT_ADJUSTMENT_INVALID',
            422,
            'The point adjustment request is invalid.'
        );
    }
}
