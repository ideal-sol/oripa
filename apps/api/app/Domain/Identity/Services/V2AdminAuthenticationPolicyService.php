<?php

namespace App\Domain\Identity\Services;

use App\Domain\Audit\V2\Services\V2AuditLogService;
use App\Domain\Identity\Contracts\V2AdminAuthorizationContext;
use App\Domain\Identity\Enums\V2AdminRole;
use App\Domain\Identity\Enums\V2AdminState;
use App\Domain\Identity\Enums\V2Permission;
use App\Domain\Identity\Exceptions\V2AuthenticationException;
use App\Domain\Outbox\Services\V2OutboxService;
use App\Domain\Point\Exceptions\V2PointException;
use App\Domain\Point\Services\V2PointIdempotencyService;
use App\Models\V2\Admin;
use App\Models\V2\AdminAuthenticationPolicy;
use App\Models\V2\AdminInvitation;
use App\Models\V2\AdminTotpMethod;
use App\Models\V2\AdminWebauthnMethod;
use Illuminate\Support\Facades\DB;
use SensitiveParameter;

final class V2AdminAuthenticationPolicyService
{
    public function __construct(
        private readonly V2AdminFreshMfaAuthorizer $authorization,
        private readonly V2PasswordPolicy $passwords,
        private readonly V2EmailNormalizer $emails,
        private readonly V2SecureToken $tokens,
        private readonly V2MfaPolicy $mfaPolicy,
        private readonly V2RateLimiter $rateLimiter,
        private readonly V2PointIdempotencyService $idempotency,
        private readonly V2AuditLogService $audit,
        private readonly V2OutboxService $outbox
    ) {
    }

    public function current(bool $lock = false): AdminAuthenticationPolicy
    {
        $query = AdminAuthenticationPolicy::query()->whereKey(1);
        if ($lock) {
            $query->lockForUpdate();
        }
        $policy = $query->first();
        if (! $policy instanceof AdminAuthenticationPolicy) {
            throw $this->unavailable();
        }

        return $policy;
    }

    public function mfaRequired(): bool
    {
        return (bool) $this->current()->mfa_required;
    }

    public function invitationRequired(): bool
    {
        return (bool) $this->current()->invitation_required;
    }

    /** @return array<string, mixed> */
    public function read(V2AdminAuthorizationContext $context): array
    {
        $this->owner($context, false, 'identity.admin.authentication-policy.read');

        return $this->serialize($this->current());
    }

    /**
     * @param array<string, mixed> $input
     * @return array{data: array<string, mixed>, idempotent_replay: bool}
     */
    public function update(
        V2AdminAuthorizationContext $context,
        string $idempotencyKey,
        array $input,
        #[SensitiveParameter] string $currentPassword
    ): array {
        $admin = $this->owner($context, true, 'identity.admin.authentication-policy.update');
        $this->rateLimiter->assertSubject('critical_admin_mutation', $admin->public_id);
        if (! $this->passwords->verify($currentPassword, $admin->password_hash)) {
            $this->auditFailure($context, $admin, 'invalid_current_password');
            throw new V2AuthenticationException(
                'INVALID_CURRENT_PASSWORD',
                401,
                'The current password could not be verified.'
            );
        }
        $request = $this->validatedPolicyInput($input);

        try {
            return DB::transaction(function () use (
                $context,
                $admin,
                $idempotencyKey,
                $request,
                $currentPassword
            ): array {
                $lockedAdmin = Admin::query()->whereKey($admin->getKey())->lockForUpdate()->first();
                if (! $lockedAdmin instanceof Admin
                    || $lockedAdmin->state !== V2AdminState::Active
                    || $lockedAdmin->role !== V2AdminRole::Owner) {
                    throw $this->denied();
                }
                if (! $this->passwords->verify(
                    $currentPassword,
                    $lockedAdmin->password_hash
                )) {
                    throw new V2AuthenticationException(
                        'INVALID_CURRENT_PASSWORD',
                        401,
                        'The current password could not be verified.'
                    );
                }
                $claim = $this->idempotency->claim(
                    'identity.admin.authentication_policy.update',
                    'admin',
                    $lockedAdmin->public_id,
                    $idempotencyKey,
                    $request
                );
                if ($claim->replay) {
                    $response = $claim->record->response_data;
                    if (! is_array($response) || ! isset($response['data'])) {
                        throw $this->unavailable();
                    }

                    return ['data' => $response['data'], 'idempotent_replay' => true];
                }
                $policy = $this->current(true);
                if ((int) $policy->revision !== $request['expected_revision']) {
                    throw new V2AuthenticationException(
                        'ADMIN_AUTHENTICATION_POLICY_REVISION_CONFLICT',
                        409,
                        'The authentication policy changed. Reload the canonical setting.'
                    );
                }
                if ($request['mfa_required'] && ! (bool) $policy->mfa_required
                    && ! $this->hasEligibleMfaOwner()) {
                    throw new V2AuthenticationException(
                        'MFA_OWNER_ENROLLMENT_REQUIRED',
                        409,
                        'At least one active Owner must satisfy the MFA policy before enabling it.'
                    );
                }
                if ((bool) $policy->mfa_required === $request['mfa_required']
                    && (bool) $policy->invitation_required === $request['invitation_required']) {
                    throw new V2AuthenticationException(
                        'ADMIN_AUTHENTICATION_POLICY_UNCHANGED',
                        409,
                        'The authentication policy is already in the requested state.'
                    );
                }
                $before = [
                    'mfa_required' => (bool) $policy->mfa_required,
                    'invitation_required' => (bool) $policy->invitation_required,
                    'revision' => (int) $policy->revision,
                ];
                $policy->forceFill([
                    'mfa_required' => $request['mfa_required'],
                    'invitation_required' => $request['invitation_required'],
                    'revision' => (int) $policy->revision + 1,
                    'updated_by_admin_id' => $lockedAdmin->getKey(),
                    'last_mutation_request_id' => $context->requestId,
                    'updated_at' => now()->startOfSecond(),
                ])->save();
                $data = $this->serialize($policy->refresh());
                $this->audit->record('identity.admin.authentication_policy.updated', [
                    'request_id' => $context->requestId,
                    'actor_type' => 'admin',
                    'actor_public_id' => $lockedAdmin->public_id,
                    'actor_role' => $lockedAdmin->role->value,
                    'auth_realm' => 'admin',
                    'session_correlation_hash' => $context->sessionCorrelationHash,
                    'target_type' => 'admin_authentication_policy',
                    'target_public_id' => $policy->public_id,
                    'before' => $before,
                    'after' => [
                        'mfa_required' => (bool) $policy->mfa_required,
                        'invitation_required' => (bool) $policy->invitation_required,
                        'revision' => (int) $policy->revision,
                    ],
                ]);
                $this->outbox->enqueue(
                    'identity.admin-authentication-policy-updated',
                    'admin_authentication_policy',
                    $policy->public_id,
                    'identity.admin.authentication_policy.updated',
                    ['revision' => (int) $policy->revision],
                    'admin-auth-policy:'.$policy->public_id.':'.$policy->revision
                );
                $this->idempotency->complete(
                    $claim->record,
                    'admin_authentication_policy',
                    $policy->public_id,
                    ['data' => $data]
                );

                return ['data' => $data, 'idempotent_replay' => false];
            }, 3);
        } catch (V2PointException $exception) {
            throw new V2AuthenticationException(
                $exception->getMessage() === 'IDEMPOTENCY_KEY_REUSED'
                    ? 'IDEMPOTENCY_KEY_REUSED'
                    : 'IDEMPOTENCY_REQUEST_IN_PROGRESS',
                409,
                'The authentication policy update conflicts with another request.'
            );
        }
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function createAdmin(V2AdminAuthorizationContext $context, array $input): array
    {
        $owner = $this->owner($context, true, 'identity.admin.create');
        $this->rateLimiter->assertSubject('critical_admin_mutation', $owner->public_id);
        $allowed = ['email', 'role', 'temporary_password'];
        if (array_diff(array_keys($input), $allowed) !== []) {
            throw $this->invalid();
        }
        $role = is_string($input['role'] ?? null)
            ? V2AdminRole::tryFrom($input['role'])
            : null;
        if (! in_array($role, [V2AdminRole::Admin, V2AdminRole::Operator], true)
            || ! is_string($input['email'] ?? null)) {
            throw $this->invalid();
        }
        try {
            $normalized = $this->emails->normalize($input['email']);
        } catch (\InvalidArgumentException) {
            throw $this->invalid();
        }
        $invitationRequired = $this->invitationRequired();
        $temporaryPassword = $input['temporary_password'] ?? null;
        if ($invitationRequired && $temporaryPassword !== null) {
            throw $this->invalid();
        }
        if (! $invitationRequired && ! is_string($temporaryPassword)) {
            throw $this->invalid();
        }
        try {
            $passwordHash = $invitationRequired
                ? $this->passwords->hash(bin2hex(random_bytes(32)))
                : $this->passwords->hash($temporaryPassword);
        } catch (\InvalidArgumentException) {
            throw $this->invalid();
        }
        $token = $invitationRequired ? $this->tokens->generate() : null;

        return DB::transaction(function () use (
            $context,
            $owner,
            $input,
            $normalized,
            $role,
            $passwordHash,
            $invitationRequired,
            $token
        ): array {
            $policy = $this->current(true);
            if ((bool) $policy->invitation_required !== $invitationRequired
                || Admin::query()->where('email_normalized', $normalized)->exists()) {
                throw new V2AuthenticationException(
                    'ADMIN_CREATION_CONFLICT',
                    409,
                    'The Admin account could not be created.'
                );
            }
            $admin = Admin::query()->create([
                'email_display' => trim($input['email']),
                'email_normalized' => $normalized,
                'email_verified_at' => $invitationRequired ? null : now()->startOfSecond(),
                'password_hash' => $passwordHash,
                'role' => $role,
                'state' => $invitationRequired ? V2AdminState::Invited : V2AdminState::Active,
            ]);
            $expiresAt = null;
            if ($invitationRequired && is_string($token)) {
                $createdAt = now()->startOfSecond();
                $expiresAt = $createdAt->copy()->addMinutes(30);
                AdminInvitation::query()->create([
                    'admin_id' => $admin->getKey(),
                    'token_hash' => $this->tokens->hash($token),
                    'expires_at' => $expiresAt,
                    'created_at' => $createdAt,
                ]);
            }
            $this->audit->record('identity.admin.created', [
                'request_id' => $context->requestId,
                'actor_type' => 'admin',
                'actor_public_id' => $owner->public_id,
                'actor_role' => $owner->role->value,
                'auth_realm' => 'admin',
                'session_correlation_hash' => $context->sessionCorrelationHash,
                'target_type' => 'admin',
                'target_public_id' => $admin->public_id,
                'after' => [
                    'role' => $admin->role->value,
                    'state' => $admin->state->value,
                    'invitation_required' => $invitationRequired,
                ],
            ]);
            $this->outbox->enqueue(
                'identity.admin-created',
                'admin',
                $admin->public_id,
                'identity.admin.created',
                ['role' => $admin->role->value, 'invitation_required' => $invitationRequired],
                'admin-created:'.$admin->public_id
            );

            return [
                'admin' => [
                    'id' => $admin->public_id,
                    'role' => $admin->role->value,
                    'state' => $admin->state->value,
                ],
                'invitation_token' => $token,
                'invitation_expires_at' => $expiresAt?->utc()->format('Y-m-d\TH:i:s\Z'),
            ];
        }, 3);
    }

    public function hasEligibleMfaOwner(): bool
    {
        foreach (Admin::query()
            ->where('role', V2AdminRole::Owner->value)
            ->where('state', V2AdminState::Active->value)
            ->get() as $owner) {
            if ($this->mfaSatisfied($owner)) {
                return true;
            }
        }

        return false;
    }

    public function mfaSatisfied(Admin $admin): bool
    {
        return $this->mfaPolicy->allowsAccess(
            $admin->role,
            AdminWebauthnMethod::query()
                ->where('admin_id', $admin->getKey())
                ->whereNull('revoked_at')->count(),
            AdminTotpMethod::query()
                ->where('admin_id', $admin->getKey())
                ->whereNotNull('confirmed_at')
                ->whereNull('revoked_at')->count()
        );
    }

    /** @return array<string, mixed> */
    private function serialize(AdminAuthenticationPolicy $policy): array
    {
        $activeAdmins = Admin::query()->where('state', V2AdminState::Active->value)->get();
        $enrolled = $activeAdmins->filter(fn (Admin $admin): bool =>
            AdminWebauthnMethod::query()->where('admin_id', $admin->getKey())
                ->whereNull('revoked_at')->exists()
            || AdminTotpMethod::query()->where('admin_id', $admin->getKey())
                ->whereNotNull('confirmed_at')->whereNull('revoked_at')->exists()
        )->count();

        return [
            'id' => $policy->public_id,
            'mfa_required' => (bool) $policy->mfa_required,
            'invitation_required' => (bool) $policy->invitation_required,
            'mfa_enrolled_admin_count' => $enrolled,
            'active_owner_count' => Admin::query()
                ->where('role', V2AdminRole::Owner->value)
                ->where('state', V2AdminState::Active->value)->count(),
            'revision' => (int) $policy->revision,
            'updated_at' => $policy->updated_at->utc()->format('Y-m-d\TH:i:s\Z'),
        ];
    }

    /** @param array<string, mixed> $input */
    private function validatedPolicyInput(array $input): array
    {
        $allowed = ['expected_revision', 'mfa_required', 'invitation_required'];
        if (array_diff(array_keys($input), $allowed) !== []
            || ! is_int($input['expected_revision'] ?? null)
            || $input['expected_revision'] < 1
            || ! is_bool($input['mfa_required'] ?? null)
            || ! is_bool($input['invitation_required'] ?? null)) {
            throw $this->invalid();
        }

        return $input;
    }

    private function owner(
        V2AdminAuthorizationContext $context,
        bool $fresh,
        string $action
    ): Admin {
        $admin = $this->authorization->authorizePermission(
            $context,
            V2Permission::ManageAdminIdentity,
            $fresh,
            $action
        );
        if ($admin->role !== V2AdminRole::Owner) {
            throw $this->denied();
        }

        return $admin;
    }

    private function auditFailure(
        V2AdminAuthorizationContext $context,
        Admin $admin,
        string $reason
    ): void {
        $this->audit->record('identity.admin.authentication_policy.rejected', [
            'request_id' => $context->requestId,
            'actor_type' => 'admin',
            'actor_public_id' => $admin->public_id,
            'actor_role' => $admin->role->value,
            'auth_realm' => 'admin',
            'session_correlation_hash' => $context->sessionCorrelationHash,
            'outcome' => 'failure',
            'reason_code' => $reason,
        ]);
    }

    private function denied(): V2AuthenticationException
    {
        return new V2AuthenticationException(
            'AUTHORIZATION_DENIED',
            403,
            'The Admin operation is not permitted.'
        );
    }

    private function invalid(): V2AuthenticationException
    {
        return new V2AuthenticationException(
            'ADMIN_AUTHENTICATION_POLICY_INVALID',
            422,
            'The authentication policy request is invalid.'
        );
    }

    private function unavailable(): V2AuthenticationException
    {
        return new V2AuthenticationException(
            'ADMIN_AUTHENTICATION_POLICY_UNAVAILABLE',
            503,
            'The authentication policy is unavailable.',
            true
        );
    }
}
