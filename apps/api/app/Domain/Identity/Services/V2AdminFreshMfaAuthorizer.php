<?php

namespace App\Domain\Identity\Services;

use App\Domain\Audit\V2\Services\V2AuditHasher;
use App\Domain\Audit\V2\Services\V2AuditLogService;
use App\Domain\Identity\Contracts\V2AdminAuthorizationContext;
use App\Domain\Identity\Enums\V2AdminRole;
use App\Domain\Identity\Enums\V2AdminState;
use App\Domain\Identity\Enums\V2Permission;
use App\Domain\Identity\Enums\V2Realm;
use App\Domain\Identity\Exceptions\V2AuthenticationException;
use App\Models\V2\Admin;
use App\Models\V2\AdminSession;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

final class V2AdminFreshMfaAuthorizer
{
    public function __construct(
        private readonly V2SessionManager $sessions,
        private readonly V2PermissionAuthorizer $permissions,
        private readonly V2RateLimiter $rateLimiter,
        private readonly V2AuditLogService $audit,
        private readonly V2AuditHasher $auditHasher
    ) {
    }

    public function context(Request $request, ?string $requestId = null): V2AdminAuthorizationContext
    {
        $hash = $this->sessions->sessionIdHash($request, V2Realm::Admin);
        if ($hash === null) {
            throw $this->authenticationRequired();
        }
        $session = $this->validSession($hash);
        $admin = $session?->admin;
        if (! $admin instanceof Admin) {
            throw $this->authenticationRequired();
        }

        return new V2AdminAuthorizationContext(
            (int) $admin->getKey(),
            $admin->public_id,
            $admin->role,
            $hash,
            $this->auditHasher->correlation($hash),
            is_string($requestId) && Str::isUuid($requestId)
                ? $requestId
                : (string) Str::uuid7()
        );
    }

    public function authorizeQa(
        V2AdminAuthorizationContext $context,
        bool $criticalMutation = false
    ): Admin {
        [$session, $admin] = $this->sessionAndAdmin($context);
        if (! $this->permissions->allows($admin->role, V2Permission::ManageQaDraw)) {
            throw new V2AuthenticationException(
                'AUTHORIZATION_DENIED',
                403,
                'The QA Draw operation is restricted to Owners.'
            );
        }
        if (! $this->isFresh($session)) {
            $this->audit->record('admin.fresh_mfa.required', [
                'request_id' => $context->requestId,
                'actor_type' => 'admin',
                'actor_public_id' => $admin->public_id,
                'actor_role' => $admin->role->value,
                'auth_realm' => 'admin',
                'session_correlation_hash' => $context->sessionCorrelationHash,
                'outcome' => 'failure',
                'reason_code' => 'fresh_authentication_required',
            ]);
            throw new V2AuthenticationException(
                'FRESH_AUTHENTICATION_REQUIRED',
                403,
                'Fresh authentication is required.'
            );
        }
        if ($criticalMutation) {
            $this->rateLimiter->assertSubject(
                'critical_admin_mutation',
                $admin->public_id
            );
        }

        return $admin;
    }

    public function authorizeReporting(
        V2AdminAuthorizationContext $context,
        bool $financialExport = false
    ): Admin {
        [$session, $admin] = $this->sessionAndAdmin($context);
        $permission = $financialExport
            ? V2Permission::ExportFinancialReporting
            : V2Permission::ReadFinancialReporting;
        if (! $this->permissions->allows($admin->role, $permission)) {
            throw new V2AuthenticationException(
                'AUTHORIZATION_DENIED',
                403,
                'The reporting operation is not permitted.'
            );
        }
        if (! $financialExport) {
            return $admin;
        }
        if (! $this->isFresh($session)) {
            $this->audit->record('admin.fresh_mfa.required', [
                'request_id' => $context->requestId,
                'actor_type' => 'admin',
                'actor_public_id' => $admin->public_id,
                'actor_role' => $admin->role->value,
                'auth_realm' => 'admin',
                'session_correlation_hash' => $context->sessionCorrelationHash,
                'action' => 'reporting.export',
                'outcome' => 'failure',
                'reason_code' => 'fresh_authentication_required',
            ]);
            throw new V2AuthenticationException(
                'FRESH_AUTHENTICATION_REQUIRED',
                403,
                'Fresh authentication is required.'
            );
        }
        try {
            $this->rateLimiter->assertSubject(
                'financial_export',
                $admin->public_id
            );
        } catch (V2AuthenticationException $exception) {
            $this->audit->record(
                $exception->errorCode === 'RATE_LIMITED'
                    ? 'report.export.rate_limited'
                    : 'report.export.authorization_failed',
                [
                    'request_id' => $context->requestId,
                    'actor_type' => 'admin',
                    'actor_public_id' => $admin->public_id,
                    'actor_role' => $admin->role->value,
                    'auth_realm' => 'admin',
                    'session_correlation_hash' => $context->sessionCorrelationHash,
                    'action' => 'reporting.export',
                    'outcome' => 'failure',
                    'reason_code' => strtolower($exception->errorCode),
                ]
            );
            throw $exception;
        }

        return $admin;
    }

    public function authorizePermission(
        V2AdminAuthorizationContext $context,
        V2Permission $permission,
        bool $freshMfa = false,
        string $action = 'admin.operation',
        bool $criticalMutation = false
    ): Admin {
        [$session, $admin] = $this->sessionAndAdmin($context);
        if (! $this->permissions->allows($admin->role, $permission)) {
            throw new V2AuthenticationException(
                'AUTHORIZATION_DENIED',
                403,
                'The Admin operation is not permitted.'
            );
        }
        if (! $freshMfa) {
            if ($criticalMutation) {
                $this->rateLimiter->assertSubject(
                    'critical_admin_mutation',
                    $admin->public_id
                );
            }

            return $admin;
        }
        if (! $this->isFresh($session)) {
            $this->audit->record('admin.fresh_mfa.required', [
                'request_id' => $context->requestId,
                'actor_type' => 'admin',
                'actor_public_id' => $admin->public_id,
                'actor_role' => $admin->role->value,
                'auth_realm' => 'admin',
                'session_correlation_hash' => $context->sessionCorrelationHash,
                'outcome' => 'failure',
                'reason_code' => 'fresh_authentication_required',
                'metadata' => ['action' => $action],
            ]);
            throw new V2AuthenticationException(
                'FRESH_AUTHENTICATION_REQUIRED',
                403,
                'Fresh authentication is required.'
            );
        }
        if ($criticalMutation) {
            $this->rateLimiter->assertSubject(
                'critical_admin_mutation',
                $admin->public_id
            );
        }

        return $admin;
    }

    public function validSessionForReauthentication(
        V2AdminAuthorizationContext $context,
        bool $lock = false
    ): AdminSession {
        $session = $this->validSession($context->sessionIdHash, $lock);
        if (
            ! $session instanceof AdminSession
            || (int) $session->admin_id !== $context->adminId
            || $session->requires_mfa_enrollment
        ) {
            throw $this->authenticationRequired();
        }

        return $session;
    }

    public function isFresh(AdminSession $session): bool
    {
        if ($session->mfa_verified_at === null) {
            return false;
        }
        $minutes = config('v2_identity.fresh_mfa.minutes');
        if (! is_int($minutes) || $minutes !== 5) {
            throw new \RuntimeException('Admin Fresh MFA configuration is invalid.');
        }

        return CarbonImmutable::now()->lessThan(
            CarbonImmutable::parse($session->mfa_verified_at)->addMinutes($minutes)
        );
    }

    private function validSession(string $hash, bool $lock = false): ?AdminSession
    {
        $query = AdminSession::query()
            ->with('admin')
            ->whereKey($hash)
            ->whereNull('revoked_at')
            ->where('idle_expires_at', '>', now())
            ->where('absolute_expires_at', '>', now())
            ->where('requires_mfa_enrollment', false);
        if ($lock) {
            $query->lockForUpdate();
        }
        $session = $query->first();
        if (
            ! $session instanceof AdminSession
            || ! $session->admin instanceof Admin
            || $session->admin->state !== V2AdminState::Active
        ) {
            return null;
        }

        return $session;
    }

    /** @return array{AdminSession, Admin} */
    private function sessionAndAdmin(
        V2AdminAuthorizationContext $context
    ): array {
        $session = $this->validSession($context->sessionIdHash);
        $admin = $session?->admin;
        if (
            ! $session instanceof AdminSession
            || ! $admin instanceof Admin
            || (int) $admin->getKey() !== $context->adminId
            || ! hash_equals($admin->public_id, $context->adminPublicId)
            || $admin->role !== $context->role
        ) {
            throw $this->authenticationRequired();
        }

        return [$session, $admin];
    }

    private function authenticationRequired(): V2AuthenticationException
    {
        return new V2AuthenticationException(
            'AUTHENTICATION_REQUIRED',
            401,
            'Admin authentication is required.'
        );
    }
}
