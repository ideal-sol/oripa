<?php

namespace App\Domain\Outbox\Services;

use App\Models\V2\OutboxMessage;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

final class V2OutboxService
{
    private const PROHIBITED_KEY_PARTS = [
        'authorization',
        'cookie',
        'credential',
        'email',
        'password',
        'recovery_code',
        'secret',
        'session',
        'token',
    ];

    /**
     * @param array<string, mixed> $payload
     */
    public function enqueue(
        string $topic,
        string $aggregateType,
        ?string $aggregatePublicId,
        string $eventType,
        array $payload,
        string $deduplicationKey,
        ?\DateTimeInterface $availableAt = null
    ): OutboxMessage {
        if (DB::transactionLevel() < 1) {
            throw new RuntimeException('Outbox enqueue requires an active domain transaction.');
        }
        $this->assertCode($topic, 128, 'Outbox topic');
        $this->assertCode($aggregateType, 64, 'Outbox aggregate type');
        $this->assertCode($eventType, 128, 'Outbox event type');
        $this->assertDeduplicationKey($deduplicationKey);
        $this->assertPayload($payload);
        $payload = $this->normalizePayload($payload);
        if ($aggregatePublicId !== null && ! Str::isUuid($aggregatePublicId)) {
            throw new RuntimeException('Outbox aggregate public ID is invalid.');
        }
        $now = now()->startOfSecond();
        $attributes = [
            'public_id' => (string) Str::uuid(),
            'topic' => $topic,
            'aggregate_type' => $aggregateType,
            'aggregate_public_id' => $aggregatePublicId,
            'event_type' => $eventType,
            'payload' => $payload,
            'deduplication_key' => $deduplicationKey,
            'status' => 'pending',
            'available_at' => CarbonImmutable::parse($availableAt ?? now())->startOfSecond(),
            'attempts' => 0,
            'created_at' => $now,
            'updated_at' => $now,
        ];
        $insert = [
            ...$attributes,
            'payload' => json_encode($payload, JSON_THROW_ON_ERROR),
        ];
        DB::table('outbox_messages')->insertOrIgnore($insert);
        $message = OutboxMessage::query()
            ->where('deduplication_key', $deduplicationKey)
            ->first();
        if ($message === null || ! $this->sameMessage($message, $attributes)) {
            throw new RuntimeException('Outbox deduplication conflict.');
        }

        return $message;
    }

    /**
     * @return Collection<int, OutboxMessage>
     */
    public function claim(string $worker, int $limit = 10, ?int $leaseSeconds = null): Collection
    {
        $this->assertWorker($worker);
        $maximum = (int) config('v2_outbox.maximum_claim_size', 100);
        if ($limit < 1 || $limit > $maximum) {
            throw new RuntimeException('Outbox claim size is invalid.');
        }
        $leaseSeconds ??= (int) config('v2_outbox.default_lease_seconds', 60);
        if ($leaseSeconds < 1 || $leaseSeconds > 3600) {
            throw new RuntimeException('Outbox lease duration is invalid.');
        }

        return DB::transaction(function () use ($worker, $limit, $leaseSeconds): Collection {
            $now = now()->startOfSecond();
            $messages = OutboxMessage::query()
                ->where(function ($query) use ($now): void {
                    $query
                        ->where(function ($pending) use ($now): void {
                            $pending
                                ->where('status', 'pending')
                                ->where('available_at', '<=', $now);
                        })
                        ->orWhere(function ($expired) use ($now): void {
                            $expired
                                ->where('status', 'processing')
                                ->where('lease_expires_at', '<=', $now);
                        });
                })
                ->orderBy('id')
                ->limit($limit)
                ->lock('FOR UPDATE SKIP LOCKED')
                ->get();
            $claimedIds = [];
            foreach ($messages as $message) {
                $updated = DB::table('outbox_messages')
                    ->where('id', $message->id)
                    ->where(function ($eligible) use ($now): void {
                        $eligible
                            ->where(function ($pending) use ($now): void {
                                $pending
                                    ->where('status', 'pending')
                                    ->where('available_at', '<=', $now);
                            })
                            ->orWhere(function ($expired) use ($now): void {
                                $expired
                                    ->where('status', 'processing')
                                    ->where('lease_expires_at', '<=', $now);
                            });
                    })
                    ->update([
                        'status' => 'processing',
                        'attempts' => DB::raw('attempts + 1'),
                        'locked_at' => $now,
                        'locked_by' => $worker,
                        'lease_expires_at' => $now->copy()->addSeconds($leaseSeconds),
                        'last_error_code' => null,
                        'updated_at' => $now,
                    ]);
                if ($updated === 1) {
                    $claimedIds[] = $message->id;
                }
            }

            return OutboxMessage::query()
                ->whereIn('id', $claimedIds)
                ->orderBy('id')
                ->get();
        }, 3);
    }

    public function markDelivered(string $publicId, string $worker): void
    {
        $this->transition($publicId, $worker, function (OutboxMessage $message): void {
            $message->forceFill([
                'status' => 'delivered',
                'locked_at' => null,
                'locked_by' => null,
                'lease_expires_at' => null,
                'delivered_at' => now()->startOfSecond(),
                'last_error_code' => null,
            ])->save();
        });
    }

    public function retry(
        string $publicId,
        string $worker,
        string $errorCode,
        int $delaySeconds
    ): void {
        $this->assertCode($errorCode, 64, 'Outbox error code');
        if ($delaySeconds < 1 || $delaySeconds > 86400) {
            throw new RuntimeException('Outbox retry delay is invalid.');
        }
        $this->transition(
            $publicId,
            $worker,
            function (OutboxMessage $message) use ($errorCode, $delaySeconds): void {
                $message->forceFill([
                    'status' => 'pending',
                    'available_at' => now()->startOfSecond()->addSeconds($delaySeconds),
                    'locked_at' => null,
                    'locked_by' => null,
                    'lease_expires_at' => null,
                    'last_error_code' => $errorCode,
                ])->save();
            }
        );
    }

    public function markFailed(string $publicId, string $worker, string $errorCode): void
    {
        $this->assertCode($errorCode, 64, 'Outbox error code');
        $this->transition(
            $publicId,
            $worker,
            function (OutboxMessage $message) use ($errorCode): void {
                $message->forceFill([
                    'status' => 'failed',
                    'locked_at' => null,
                    'locked_by' => null,
                    'lease_expires_at' => null,
                    'last_error_code' => $errorCode,
                ])->save();
            }
        );
    }

    private function transition(string $publicId, string $worker, callable $transition): void
    {
        $this->assertWorker($worker);
        DB::transaction(function () use ($publicId, $worker, $transition): void {
            $message = OutboxMessage::query()
                ->where('public_id', $publicId)
                ->lockForUpdate()
                ->firstOrFail();
            if (
                $message->status !== 'processing'
                || ! hash_equals((string) $message->locked_by, $worker)
                || $message->lease_expires_at === null
                || $message->lease_expires_at->isPast()
            ) {
                throw new RuntimeException('Outbox lease ownership is invalid.');
            }
            $transition($message);
        }, 3);
    }

    private function sameMessage(OutboxMessage $message, array $attributes): bool
    {
        return $message->topic === $attributes['topic']
            && $message->aggregate_type === $attributes['aggregate_type']
            && $message->aggregate_public_id === $attributes['aggregate_public_id']
            && $message->event_type === $attributes['event_type']
            && $message->payload === $attributes['payload'];
    }

    private function assertPayload(array $payload): void
    {
        $this->walkPayload($payload);
        $encoded = json_encode($payload, JSON_THROW_ON_ERROR);
        if (strlen($encoded) > 131072) {
            throw new RuntimeException('Outbox payload is too large.');
        }
    }

    private function walkPayload(array $payload): void
    {
        foreach ($payload as $key => $value) {
            if (is_string($key)) {
                $lower = strtolower($key);
                foreach (self::PROHIBITED_KEY_PARTS as $prohibited) {
                    if (str_contains($lower, $prohibited)) {
                        throw new RuntimeException('Outbox payload contains prohibited sensitive data.');
                    }
                }
            }
            if (is_array($value)) {
                $this->walkPayload($value);
            } elseif (
                ! is_bool($value)
                && ! is_float($value)
                && ! is_int($value)
                && ! is_string($value)
                && $value !== null
            ) {
                throw new RuntimeException('Outbox payload contains an unsupported value.');
            }
        }
    }

    private function normalizePayload(array $payload): array
    {
        if (array_is_list($payload)) {
            return array_map(
                fn (mixed $value): mixed => is_array($value)
                    ? $this->normalizePayload($value)
                    : $value,
                $payload
            );
        }
        ksort($payload, SORT_STRING);
        foreach ($payload as $key => $value) {
            if (is_array($value)) {
                $payload[$key] = $this->normalizePayload($value);
            }
        }

        return $payload;
    }

    private function assertCode(string $value, int $maximum, string $label): void
    {
        if (
            $value === ''
            || strlen($value) > $maximum
            || ! preg_match('/\A[a-z][a-z0-9_.:-]*\z/', $value)
        ) {
            throw new RuntimeException($label.' is invalid.');
        }
    }

    private function assertDeduplicationKey(string $value): void
    {
        if (
            $value === ''
            || strlen($value) > 255
            || ! preg_match('/\A[a-zA-Z0-9][a-zA-Z0-9_.:-]*\z/', $value)
        ) {
            throw new RuntimeException('Outbox deduplication key is invalid.');
        }
    }

    private function assertWorker(string $worker): void
    {
        if (
            $worker === ''
            || strlen($worker) > 128
            || ! preg_match('/\A[a-zA-Z0-9][a-zA-Z0-9_.:-]*\z/', $worker)
        ) {
            throw new RuntimeException('Outbox worker identity is invalid.');
        }
    }
}
