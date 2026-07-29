<?php

namespace App\Domain\Audit\V2\Services;

use App\Domain\Identity\Contracts\V2SecurityEventSink;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use RuntimeException;

final class V2PersistentSecurityEventSink implements V2SecurityEventSink
{
    private const ACTIONS = [
        'admin_invitation' => 'identity.admin_invitation',
        'login_failure' => 'identity.login_failure',
        'login_success' => 'identity.login_success',
        'logout' => 'identity.logout',
        'mfa_enrollment' => 'identity.mfa_enrollment',
        'mfa_failure' => 'identity.mfa_failure',
        'mfa_success' => 'identity.mfa_success',
        'rate_limit_trigger' => 'identity.rate_limit_trigger',
        'recovery_code_use' => 'identity.recovery_code_use',
        'register' => 'identity.register',
        'password_reset_failed' => 'identity.password_reset.failed',
        'password_reset_rate_limited' => 'identity.password_reset.rate_limited',
        'password_reset_requested' => 'identity.password_reset.requested',
        'password_reset_succeeded' => 'identity.password_reset.succeeded',
        'phone_changed' => 'identity.phone.changed',
        'sms_verification_failed' => 'identity.sms_verification.failed',
        'sms_verification_rate_limited' => 'identity.sms_verification.rate_limited',
        'sms_verification_sent' => 'identity.sms_verification.sent',
        'sms_verification_succeeded' => 'identity.sms_verification.succeeded',
        'user_sessions_revoked' => 'identity.user_sessions.revoked',
        'verification_failure' => 'identity.verification_failure',
        'verification_success' => 'identity.verification_success',
        'external_email_conflict' => 'identity.external.email_conflict',
        'external_email_required' => 'identity.external.email.required',
        'external_identity_rate_limited' => 'identity.external.rate_limited',
        'external_link_rejected' => 'identity.external.link.rejected',
        'external_link_succeeded' => 'identity.external.link.succeeded',
        'external_login_rejected' => 'identity.external.login.rejected',
        'external_login_succeeded' => 'identity.external.login.succeeded',
        'external_unlink_rejected' => 'identity.external.unlink.rejected',
        'external_unlink_succeeded' => 'identity.external.unlink.succeeded',
        'external_user_created' => 'identity.external.user.created',
        'oidc_nonce_rejected' => 'identity.oidc.nonce.rejected',
        'oidc_pkce_rejected' => 'identity.oidc.pkce.rejected',
        'oidc_provider_failure' => 'identity.oidc.provider.failure',
        'oidc_signature_rejected' => 'identity.oidc.signature.rejected',
        'oidc_start' => 'identity.oidc.start',
        'oidc_state_rejected' => 'identity.oidc.state.rejected',
        'user_reauthentication_failed' => 'identity.user.reauthentication.failed',
        'user_reauthentication_succeeded' => 'identity.user.reauthentication.succeeded',
    ];

    private const CONTEXT_KEYS = [
        'method',
        'realm',
        'reason',
        'result',
        'role',
        'stage',
        'subject_id',
    ];

    public function __construct(
        private readonly V2AuditLogService $audit,
        private readonly V2AuditHasher $hasher
    ) {
    }

    public function record(string $event, array $context): void
    {
        if (! isset(self::ACTIONS[$event]) || array_diff(array_keys($context), self::CONTEXT_KEYS)) {
            throw new RuntimeException('Security event is outside the persistent audit allowlist.');
        }
        $realm = $context['realm'] ?? 'system';
        if (! is_string($realm) || ! in_array($realm, ['user', 'admin', 'system'], true)) {
            throw new RuntimeException('Security event realm is invalid.');
        }
        $subjectId = $context['subject_id'] ?? null;
        if ($subjectId !== null && (! is_string($subjectId) || ! Str::isUuid($subjectId))) {
            throw new RuntimeException('Security event subject is invalid.');
        }
        $request = app()->bound('request') ? app('request') : null;
        $request = $request instanceof Request ? $request : null;
        $metadata = array_intersect_key($context, array_flip(['method', 'result', 'stage']));
        $failure = str_ends_with($event, '_failure')
            || str_ends_with($event, '_failed')
            || str_ends_with($event, '_rejected')
            || str_ends_with($event, '_rate_limited')
            || $event === 'rate_limit_trigger';
        $outcome = $failure ? 'failure' : ($event === 'register' ? 'pending' : 'success');

        $this->audit->record(self::ACTIONS[$event], [
            'request_id' => $this->requestId($request),
            'actor_type' => $subjectId === null ? 'system' : $realm,
            'actor_public_id' => $subjectId,
            'actor_role' => $context['role'] ?? null,
            'auth_realm' => $realm,
            'session_correlation_hash' => $this->sessionCorrelation($request, $realm),
            'target_type' => $realm === 'system' ? 'security_event' : $realm.'_account',
            'target_public_id' => $subjectId,
            'outcome' => $outcome,
            'reason_code' => $context['reason'] ?? null,
            'metadata' => $metadata,
            'ip' => $request?->ip(),
            'user_agent' => $request?->userAgent(),
        ]);
    }

    private function requestId(?Request $request): string
    {
        $value = $request?->headers->get('X-Request-ID');

        return is_string($value) && Str::isUuid($value) ? $value : (string) Str::uuid();
    }

    private function sessionCorrelation(?Request $request, string $realm): ?string
    {
        if ($request === null || ! in_array($realm, ['user', 'admin'], true)) {
            return null;
        }
        $cookie = config('v2_identity.sessions.'.$realm.'.cookie');
        $raw = is_string($cookie) ? $request->cookies->get($cookie) : null;

        return is_string($raw) && $raw !== '' ? $this->hasher->correlation($raw) : null;
    }
}
