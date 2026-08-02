<?php

namespace App\Domain\Identity\Services;

use App\Domain\Audit\V2\Services\V2AuditLogService;
use App\Domain\Identity\Contracts\V2AdminAuthorizationContext;
use App\Domain\Identity\Exceptions\V2AuthenticationException;
use App\Models\V2\Admin;
use App\Models\V2\AdminTotpMethod;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use SensitiveParameter;

final class V2AdminReauthenticationService
{
    public function __construct(
        private readonly V2AdminFreshMfaAuthorizer $authorizer,
        private readonly V2RateLimiter $rateLimiter,
        private readonly V2TotpService $totp,
        private readonly V2WebauthnService $webauthn,
        private readonly V2PasswordPolicy $passwords,
        private readonly V2AdminAuthenticationPolicyService $authenticationPolicy,
        private readonly V2SessionManager $sessions,
        private readonly V2AuditLogService $audit
    ) {
    }

    public function context(
        Request $request,
        string $requestId
    ): V2AdminAuthorizationContext {
        return $this->authorizer->context($request, $requestId);
    }

    /**
     * @return array{challenge_token: string, options: array<string, mixed>, expires_in: int}
     */
    public function beginWebauthn(V2AdminAuthorizationContext $context): array
    {
        $session = $this->authorizer->validSessionForReauthentication($context);
        $result = $this->webauthn->beginReauthenticationAssertion(
            $session->admin,
            $context->sessionIdHash
        );
        if ($result === null) {
            throw $this->invalidMfa();
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $credential
     * @return array{
     *   admin: Admin,
     *   session: array{token: string, absolute_expires_at: \DateTimeInterface},
     *   fresh_mfa_expires_in: int
     * }
     */
    public function reauthenticate(
        V2AdminAuthorizationContext $context,
        string $method,
        #[SensitiveParameter] ?string $code = null,
        #[SensitiveParameter] ?string $challengeToken = null,
        array $credential = [],
        #[SensitiveParameter] ?string $password = null
    ): array {
        try {
            $this->rateLimiter->assertSubject('mfa_verify', $context->sessionIdHash);
        } catch (V2AuthenticationException $exception) {
            $this->auditEvent(
                $exception->status === 429
                    ? 'admin.reauthentication.rate_limited'
                    : 'admin.reauthentication.failed',
                $context,
                'failure',
                $exception->errorCode,
                $method
            );
            throw $exception;
        }

        try {
            $result = DB::transaction(function () use (
                $context,
                $method,
                $code,
                $challengeToken,
                $credential,
                $password
            ): array {
                $session = $this->authorizer->validSessionForReauthentication(
                    $context,
                    true
                );
                $admin = $session->admin;
                $verified = match ($method) {
                    'totp' => $this->verifyTotp($admin, $code),
                    'webauthn' => is_string($challengeToken)
                        && $this->webauthn->verifyReauthenticationAssertion(
                            $admin,
                            $challengeToken,
                            $credential,
                            $context->sessionIdHash
                        ),
                    'password' => ! $this->authenticationPolicy->mfaRequired()
                        && is_string($password)
                        && $this->passwords->verify($password, $admin->password_hash),
                    default => false,
                };
                if (! $verified) {
                    throw $this->invalidMfa();
                }
                $rotated = $this->sessions->rotateLockedAdminSession($session);
                $this->auditEvent(
                    'admin.reauthentication.succeeded',
                    $context,
                    'success',
                    null,
                    $method
                );

                return [
                    'admin' => $admin,
                    'session' => $rotated,
                    'fresh_mfa_expires_in' => 300,
                ];
            }, 3);
        } catch (V2AuthenticationException $exception) {
            $this->auditEvent(
                'admin.reauthentication.failed',
                $context,
                'failure',
                'invalid_mfa_credential',
                $method
            );
            throw $exception;
        }

        return $result;
    }

    private function verifyTotp(
        Admin $admin,
        #[SensitiveParameter] ?string $code
    ): bool {
        if (! is_string($code)) {
            return false;
        }
        $methods = AdminTotpMethod::query()
            ->where('admin_id', $admin->getKey())
            ->whereNotNull('confirmed_at')
            ->whereNull('revoked_at')
            ->orderBy('id')
            ->lockForUpdate()
            ->get();
        foreach ($methods as $method) {
            if ($this->totp->verify($method, $code)) {
                return true;
            }
        }

        return false;
    }

    private function auditEvent(
        string $action,
        V2AdminAuthorizationContext $context,
        string $outcome,
        ?string $reason,
        string $method
    ): void {
        $this->audit->record($action, [
            'request_id' => $context->requestId,
            'actor_type' => 'admin',
            'actor_public_id' => $context->adminPublicId,
            'actor_role' => $context->role->value,
            'auth_realm' => 'admin',
            'session_correlation_hash' => $context->sessionCorrelationHash,
            'outcome' => $outcome,
            'reason_code' => $reason === null ? null : strtolower($reason),
            'metadata' => ['mfa_method' => $method],
        ]);
    }

    private function invalidMfa(): V2AuthenticationException
    {
        return new V2AuthenticationException(
            'INVALID_MFA_CREDENTIAL',
            401,
            'The MFA verification could not be completed.'
        );
    }
}
