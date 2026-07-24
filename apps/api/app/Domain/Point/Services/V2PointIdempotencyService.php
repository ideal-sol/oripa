<?php

namespace App\Domain\Point\Services;

use App\Domain\Point\Exceptions\V2PointException;
use App\Domain\Point\ValueObjects\V2IdempotencyClaim;
use App\Models\V2\IdempotencyRecord;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use JsonException;
use SensitiveParameter;

final class V2PointIdempotencyService
{
    /**
     * @param array<string, mixed> $request
     */
    public function claim(
        string $scope,
        string $actorType,
        string $actorPublicId,
        #[SensitiveParameter] string $key,
        array $request
    ): V2IdempotencyClaim {
        if (DB::transactionLevel() < 1) {
            throw new V2PointException('Point idempotency requires an active transaction.');
        }
        if (
            ! preg_match('/\A[a-z][a-z0-9_.:-]{0,63}\z/', $scope)
            || ! in_array($actorType, ['system', 'user', 'admin', 'webhook'], true)
            || ! Str::isUuid($actorPublicId)
            || $key === ''
            || strlen($key) > 255
        ) {
            throw new V2PointException('Point idempotency input is invalid.');
        }

        $keyHash = hash('sha256', $key);
        $requestHash = hash('sha256', $this->canonicalJson($request));
        $publicId = (string) Str::uuid7();
        $inserted = DB::select(
            <<<'SQL'
                INSERT INTO idempotency_records (
                    public_id, scope, actor_type, actor_public_id, key_hash,
                    request_hash, status, created_at, expires_at
                ) VALUES (?, ?, ?, ?::uuid, ?, ?, 'processing', ?, ?)
                ON CONFLICT (scope, actor_type, actor_public_id, key_hash) DO NOTHING
                RETURNING id
            SQL,
            [
                $publicId,
                $scope,
                $actorType,
                $actorPublicId,
                $keyHash,
                $requestHash,
                now(),
                now()->addDay(),
            ]
        );
        $record = IdempotencyRecord::query()
            ->where('scope', $scope)
            ->where('actor_type', $actorType)
            ->where('actor_public_id', $actorPublicId)
            ->where('key_hash', $keyHash)
            ->lockForUpdate()
            ->firstOrFail();

        if ($record->request_hash !== $requestHash) {
            throw new V2PointException('IDEMPOTENCY_KEY_REUSED');
        }
        if ($inserted !== []) {
            return new V2IdempotencyClaim($record, false);
        }
        if ($record->status === 'completed') {
            return new V2IdempotencyClaim($record, true);
        }

        throw new V2PointException('IDEMPOTENCY_REQUEST_IN_PROGRESS');
    }

    /**
     * @param array<string, mixed> $response
     */
    public function complete(
        IdempotencyRecord $record,
        string $resourceType,
        string $resourcePublicId,
        array $response = []
    ): void {
        if (
            DB::transactionLevel() < 1
            || ! preg_match('/\A[a-z][a-z0-9_.:-]{0,63}\z/', $resourceType)
            || ! Str::isUuid($resourcePublicId)
        ) {
            throw new V2PointException('Point idempotency completion is invalid.');
        }
        IdempotencyRecord::query()->whereKey($record->getKey())->update([
            'status' => 'completed',
            'resource_type' => $resourceType,
            'resource_public_id' => $resourcePublicId,
            'response_status' => 200,
            'response_data' => $response === [] ? null : json_encode(
                $response,
                JSON_THROW_ON_ERROR
            ),
            'completed_at' => now(),
        ]);
    }

    /**
     * @param array<string, mixed> $value
     */
    private function canonicalJson(array $value): string
    {
        try {
            return json_encode(
                $this->sortRecursively($value),
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
            );
        } catch (JsonException $exception) {
            throw new V2PointException('Point idempotency request is not serializable.', 0, $exception);
        }
    }

    private function sortRecursively(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        if (array_is_list($value)) {
            return array_map($this->sortRecursively(...), $value);
        }
        ksort($value);

        return array_map($this->sortRecursively(...), $value);
    }
}
