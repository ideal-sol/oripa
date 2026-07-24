<?php

namespace App\Domain\Identity\Services;

use App\Domain\Identity\Contracts\V2SecurityEventSink;
use App\Domain\Identity\Events\V2SecurityEvent;
use RuntimeException;

final class V2DeferredSecurityEventSink implements V2SecurityEventSink
{
    private const FORBIDDEN_KEYS = [
        'password',
        'token',
        'session_id',
        'mfa_secret',
        'recovery_code',
        'email',
    ];

    public function record(string $event, array $context): void
    {
        $keys = array_map('strtolower', array_keys($context));
        if (array_intersect(self::FORBIDDEN_KEYS, $keys) !== []) {
            throw new RuntimeException('Security event contains prohibited sensitive data.');
        }
        if (app()->environment('production') && ! config('v2_identity.audit_persistence_ready')) {
            throw new RuntimeException('Production authentication is disabled until audit persistence is ready.');
        }

        event(new V2SecurityEvent($event, $context));
    }
}
