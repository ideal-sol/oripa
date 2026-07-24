<?php

namespace App\Domain\Audit\V2\Services;

use App\Models\V2\AuditLog;

final class V2AuditChainVerifier
{
    public function __construct(
        private readonly V2AuditHasher $hasher,
        private readonly V2AuditLogService $audit
    ) {
    }

    public function verify(): bool
    {
        $previous = null;
        foreach (AuditLog::query()->orderBy('id')->cursor() as $record) {
            if (! hash_equals((string) ($record->previous_hash ?? ''), (string) ($previous ?? ''))) {
                return false;
            }
            $payload = $this->audit->hashPayload([
                ...$record->getAttributes(),
                'occurred_at' => $record->occurred_at,
                'business_date' => $record->business_date->toDateString(),
                'before_redacted' => $record->before_redacted,
                'after_redacted' => $record->after_redacted,
                'metadata_redacted' => $record->metadata_redacted,
            ]);
            $expected = $this->hasher->digest($payload, $record->hmac_key_version);
            if (! hash_equals($expected, $record->record_hash)) {
                return false;
            }
            $previous = $record->record_hash;
        }

        return true;
    }
}
