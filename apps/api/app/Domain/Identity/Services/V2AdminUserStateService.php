<?php

namespace App\Domain\Identity\Services;

use App\Domain\Audit\V2\Services\V2AuditLogService;
use App\Domain\Identity\Contracts\V2AdminAuthorizationContext;
use App\Domain\Identity\Enums\V2Permission;
use App\Domain\Identity\Enums\V2UserState;
use App\Domain\Identity\Exceptions\V2AdminUserStateException;
use App\Domain\Point\Exceptions\V2PointException;
use App\Domain\Point\Services\V2PointIdempotencyService;
use App\Domain\Mail\Services\V2TemplateMailDeliveryService;
use App\Models\V2\User;
use Illuminate\Support\Facades\DB;
use Normalizer;

final class V2AdminUserStateService
{
    /** @var array<string, list<string>> */
    private const TRANSITIONS = [
        'active' => ['suspended', 'closed'],
        'suspended' => ['active', 'closed'],
    ];

    public function __construct(
        private readonly V2AdminFreshMfaAuthorizer $authorization,
        private readonly V2PointIdempotencyService $idempotency,
        private readonly V2AuditLogService $audit,
        private readonly V2TemplateMailDeliveryService $templateMail
    ) {
    }

    /**
     * @param array<string, mixed> $input
     * @return array{data: array<string, mixed>, idempotent_replay: bool}
     */
    public function update(
        V2AdminAuthorizationContext $context,
        string $userPublicId,
        array $input,
        string $idempotencyKey
    ): array {
        $admin = $this->authorization->authorizePermission(
            $context,
            V2Permission::ManageUserState,
            true,
            'user.state.update',
            true
        );
        $request = $this->validate($input);

        try {
            return DB::transaction(function () use (
                $admin,
                $context,
                $idempotencyKey,
                $request,
                $userPublicId
            ): array {
                $claim = $this->idempotency->claim(
                    'user.state.update',
                    'admin',
                    $admin->public_id,
                    $idempotencyKey,
                    ['user_id' => $userPublicId, ...$request]
                );
                if ($claim->replay) {
                    $response = $claim->record->response_data;
                    if (! is_array($response) || ! is_array($response['data'] ?? null)) {
                        throw $this->unavailable();
                    }

                    return ['data' => $response['data'], 'idempotent_replay' => true];
                }

                $user = User::query()
                    ->where('public_id', $userPublicId)
                    ->lockForUpdate()
                    ->first();
                if (! $user instanceof User) {
                    throw $this->notFound();
                }
                if ((int) $user->state_revision !== $request['expected_revision']) {
                    throw new V2AdminUserStateException(
                        'ADMIN_USER_STATE_REVISION_CONFLICT',
                        409,
                        'The User state changed before this request was saved.'
                    );
                }

                $before = $user->state;
                $allowed = self::TRANSITIONS[$before->value] ?? [];
                if (! in_array($request['status'], $allowed, true)) {
                    throw new V2AdminUserStateException(
                        'ADMIN_USER_STATE_TRANSITION_INVALID',
                        409,
                        'The requested User state transition is not allowed.'
                    );
                }

                $after = V2UserState::from($request['status']);
                $now = now()->startOfSecond();
                $updated = User::query()
                    ->whereKey($user->getKey())
                    ->where('state_revision', $request['expected_revision'])
                    ->update([
                        'state' => $after->value,
                        'state_revision' => $request['expected_revision'] + 1,
                        'updated_at' => $now,
                    ]);
                if ($updated !== 1) {
                    throw new V2AdminUserStateException(
                        'ADMIN_USER_STATE_REVISION_CONFLICT',
                        409,
                        'The User state changed before this request was saved.'
                    );
                }

                $revokedSessions = 0;
                $revokedRememberDevices = 0;
                if (in_array($after, [V2UserState::Suspended, V2UserState::Closed], true)) {
                    $revokedSessions = DB::table('user_sessions')
                        ->where('user_id', $user->getKey())
                        ->whereNull('revoked_at')
                        ->update(['revoked_at' => $now]);
                    $revokedRememberDevices = DB::table('user_remember_devices')
                        ->where('user_id', $user->getKey())
                        ->whereNull('revoked_at')
                        ->update(['revoked_at' => $now]);
                }

                $data = [
                    'user_id' => $user->public_id,
                    'status' => $after->value,
                    'state_revision' => $request['expected_revision'] + 1,
                    'updated_at' => $now->toIso8601String(),
                ];
                $this->audit->record('user.state.updated', [
                    'request_id' => $context->requestId,
                    'actor_type' => 'admin',
                    'actor_public_id' => $admin->public_id,
                    'actor_role' => $admin->role->value,
                    'auth_realm' => 'admin',
                    'session_correlation_hash' => $context->sessionCorrelationHash,
                    'target_type' => 'user',
                    'target_public_id' => $user->public_id,
                    'reason_text' => $request['reason'],
                    'before' => ['status' => $before->value, 'state_revision' => $request['expected_revision']],
                    'after' => ['status' => $after->value, 'state_revision' => $request['expected_revision'] + 1],
                    'metadata' => [
                        'revoked_active_access_count' => $revokedSessions,
                        'revoked_remember_device_count' => $revokedRememberDevices,
                        'request_fingerprint' => $claim->record->key_hash,
                    ],
                ]);
                $this->idempotency->complete(
                    $claim->record,
                    'user',
                    $user->public_id,
                    ['data' => $data]
                );
                if ($after === V2UserState::Closed) {
                    $this->templateMail->schedule(
                        'user_closed',
                        'user.closed:'.$user->public_id,
                        'user',
                        $user->public_id
                    );
                }

                return ['data' => $data, 'idempotent_replay' => false];
            }, 3);
        } catch (V2PointException $exception) {
            throw match ($exception->getMessage()) {
                'IDEMPOTENCY_KEY_REUSED' => new V2AdminUserStateException(
                    'IDEMPOTENCY_KEY_REUSED',
                    409,
                    'The idempotency key was already used for another request.'
                ),
                'IDEMPOTENCY_REQUEST_IN_PROGRESS' => new V2AdminUserStateException(
                    'IDEMPOTENCY_REQUEST_IN_PROGRESS',
                    409,
                    'The User state request is already in progress.',
                    true
                ),
                default => $this->invalid(),
            };
        }
    }

    /** @param array<string, mixed> $input @return array{status: string, expected_revision: int, reason: string} */
    private function validate(array $input): array
    {
        if (array_diff(array_keys($input), ['status', 'expected_revision', 'reason']) !== []) {
            throw $this->invalid();
        }
        $status = $input['status'] ?? null;
        $revision = $input['expected_revision'] ?? null;
        $reason = $input['reason'] ?? null;
        if (
            ! is_string($status)
            || ! in_array($status, ['active', 'suspended', 'closed'], true)
            || ! is_int($revision)
            || $revision < 1
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
            || mb_strlen($normalized) > 500
            || preg_match('/[\x00-\x1F\x7F]|[<>]/u', $normalized) === 1
        ) {
            throw $this->invalid();
        }

        return ['status' => $status, 'expected_revision' => $revision, 'reason' => $normalized];
    }

    private function invalid(): V2AdminUserStateException
    {
        return new V2AdminUserStateException(
            'ADMIN_USER_STATE_INVALID',
            422,
            'The User state request is invalid.'
        );
    }

    private function notFound(): V2AdminUserStateException
    {
        return new V2AdminUserStateException(
            'ADMIN_USER_NOT_FOUND',
            404,
            'The User was not found.'
        );
    }

    private function unavailable(): V2AdminUserStateException
    {
        return new V2AdminUserStateException(
            'ADMIN_USER_STATE_UNAVAILABLE',
            503,
            'The canonical User state result is unavailable.',
            true
        );
    }
}
