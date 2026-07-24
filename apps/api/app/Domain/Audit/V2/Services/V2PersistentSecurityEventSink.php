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
        'verification_failure' => 'identity.verification_failure',
        'verification_success' => 'identity.verification_success',
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
        $failure = str_ends_with($event, '_failure') || $event === 'rate_limit_trigger';
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
