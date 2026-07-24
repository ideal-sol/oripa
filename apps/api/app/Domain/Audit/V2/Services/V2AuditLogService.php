<?php

namespace App\Domain\Audit\V2\Services;

use App\Models\V2\AuditLog;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

final class V2AuditLogService
{
    public function __construct(
        private readonly V2AuditHasher $hasher,
        private readonly V2AuditRedactor $redactor
    ) {
    }

    /**
     * @param array<string, mixed> $attributes
     */
    public function record(string $actionCode, array $attributes = []): AuditLog
    {
        $this->assertCode($actionCode, 128, 'Audit action code');

        return DB::transaction(function () use ($actionCode, $attributes): AuditLog {
            $this->lockChain();
            $previous = AuditLog::query()->orderByDesc('id')->lockForUpdate()->first();
            $applicationTimezone = config('app.timezone');
            if (! is_string($applicationTimezone) || $applicationTimezone === '') {
                throw new RuntimeException('Application timezone is invalid.');
            }
            $occurredAt = CarbonImmutable::parse($attributes['occurred_at'] ?? now())
                ->setTimezone($applicationTimezone)
                ->startOfSecond();
            $timezone = config('v2_audit.business_timezone');
            if (! is_string($timezone) || $timezone === '') {
                throw new RuntimeException('Audit business timezone is invalid.');
            }
            $keyVersion = $this->hasher->activeKeyVersion();
            $data = [
                'public_id' => (string) Str::uuid(),
                'occurred_at' => $occurredAt,
                'business_date' => $occurredAt->setTimezone($timezone)->toDateString(),
                'request_id' => $this->requestId($attributes['request_id'] ?? null),
                'actor_type' => $this->value($attributes, 'actor_type', 'system', 16),
                'actor_public_id' => $this->nullableUuid($attributes['actor_public_id'] ?? null),
                'actor_role' => $this->nullableCode($attributes['actor_role'] ?? null, 32),
                'auth_realm' => $this->nullableCode($attributes['auth_realm'] ?? null, 16),
                'session_correlation_hash' => $this->nullableHash(
                    $attributes['session_correlation_hash'] ?? null
                ),
                'action_code' => $actionCode,
                'target_type' => $this->nullableCode($attributes['target_type'] ?? null, 64),
                'target_public_id' => $this->nullableUuid($attributes['target_public_id'] ?? null),
                'outcome' => $this->value($attributes, 'outcome', 'success', 16),
                'reason_code' => $this->nullableCode($attributes['reason_code'] ?? null, 64),
                'reason_text' => $this->nullableText($attributes['reason_text'] ?? null, 500),
                'before_redacted' => $this->nullablePayload($attributes['before'] ?? null),
                'after_redacted' => $this->nullablePayload($attributes['after'] ?? null),
                'metadata_redacted' => (object) $this->redactor->sanitize(
                    $attributes['metadata'] ?? null
                ),
                'ip_correlation_hash' => $this->correlation($attributes['ip'] ?? null),
                'user_agent_hash' => $this->correlation($attributes['user_agent'] ?? null),
                'hmac_key_version' => $keyVersion,
                'previous_hash' => $previous?->record_hash,
            ];
            $data['record_hash'] = $this->hasher->digest($this->hashPayload($data), $keyVersion);
            $record = new AuditLog();
            $record->forceFill($data);
            $record->save();

            return $record->refresh();
        }, 3);
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function hashPayload(array $data): array
    {
        return [
            'public_id' => $data['public_id'],
            'occurred_at' => CarbonImmutable::parse($data['occurred_at'])
                ->utc()
                ->format('Y-m-d\TH:i:s\Z'),
            'business_date' => (string) $data['business_date'],
            'request_id' => $data['request_id'],
            'actor_type' => $data['actor_type'],
            'actor_public_id' => $data['actor_public_id'],
            'actor_role' => $data['actor_role'],
            'auth_realm' => $data['auth_realm'],
            'session_correlation_hash' => $data['session_correlation_hash'],
            'action_code' => $data['action_code'],
            'target_type' => $data['target_type'],
            'target_public_id' => $data['target_public_id'],
            'outcome' => $data['outcome'],
            'reason_code' => $data['reason_code'],
            'reason_text' => $data['reason_text'],
            'before_redacted' => $data['before_redacted'],
            'after_redacted' => $data['after_redacted'],
            'metadata_redacted' => $data['metadata_redacted'],
            'ip_correlation_hash' => $data['ip_correlation_hash'],
            'user_agent_hash' => $data['user_agent_hash'],
            'hmac_key_version' => $data['hmac_key_version'],
            'previous_hash' => $data['previous_hash'],
        ];
    }

    public function lockChain(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            throw new RuntimeException('V2 audit requires PostgreSQL.');
        }
        DB::select("SELECT pg_advisory_xact_lock(hashtextextended('v2_audit_chain', 0))");
    }

    private function requestId(mixed $value): string
    {
        return is_string($value) && Str::isUuid($value) ? $value : (string) Str::uuid();
    }

    private function value(array $attributes, string $key, string $default, int $maximum): string
    {
        $value = $attributes[$key] ?? $default;
        if (! is_string($value)) {
            throw new RuntimeException('Audit value is invalid.');
        }
        $this->assertCode($value, $maximum, 'Audit value');

        return $value;
    }

    private function nullableCode(mixed $value, int $maximum): ?string
    {
        if ($value === null) {
            return null;
        }
        if (! is_string($value)) {
            throw new RuntimeException('Audit code is invalid.');
        }
        $this->assertCode($value, $maximum, 'Audit code');

        return $value;
    }

    private function nullableText(mixed $value, int $maximum): ?string
    {
        if ($value === null) {
            return null;
        }
        if (! is_string($value) || $value === '' || strlen($value) > $maximum) {
            throw new RuntimeException('Audit text is invalid.');
        }

        return $value;
    }

    private function nullableUuid(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        if (! is_string($value) || ! Str::isUuid($value)) {
            throw new RuntimeException('Audit public ID is invalid.');
        }

        return $value;
    }

    private function nullableHash(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        if (! is_string($value) || ! preg_match('/\A[0-9a-f]{64}\z/', $value)) {
            throw new RuntimeException('Audit correlation hash is invalid.');
        }

        return $value;
    }

    private function nullablePayload(mixed $value): ?object
    {
        return $value === null ? null : (object) $this->redactor->sanitize(
            is_array($value) ? $value : throw new RuntimeException('Audit payload is invalid.')
        );
    }

    private function correlation(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        if (! is_string($value) || $value === '') {
            throw new RuntimeException('Audit correlation value is invalid.');
        }

        return $this->hasher->correlation($value);
    }

    private function assertCode(string $value, int $maximum, string $label): void
    {
        if (
            strlen($value) > $maximum
            || ! preg_match('/\A[a-z][a-z0-9_.:-]*\z/', $value)
        ) {
            throw new RuntimeException($label.' is invalid.');
        }
    }
}
