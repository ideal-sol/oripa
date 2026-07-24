<?php

namespace App\Domain\Identity\Services;

use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Support\Facades\Crypt;
use RuntimeException;
use SensitiveParameter;
use Throwable;

final class V2AuthTransactionStore
{
    public function __construct(
        private readonly CacheFactory $cache,
        private readonly V2SecureToken $tokens
    ) {
    }

    /**
     * @param array<string, mixed> $payload
     * @return array{token: string, expires_in: int}
     */
    public function issue(string $purpose, array $payload, int $ttlSeconds): array
    {
        if ($ttlSeconds < 1) {
            throw new RuntimeException('Authentication transaction configuration is invalid.');
        }

        $token = $this->tokens->generate();
        $encrypted = Crypt::encryptString(json_encode(
            ['purpose' => $purpose, 'payload' => $payload],
            JSON_THROW_ON_ERROR
        ));

        try {
            $stored = $this->repository()->put($this->key($token), $encrypted, $ttlSeconds);
        } catch (Throwable $exception) {
            throw new RuntimeException('Authentication transaction storage is unavailable.', 0, $exception);
        }
        if ($stored === false) {
            throw new RuntimeException('Authentication transaction storage is unavailable.');
        }

        return ['token' => $token, 'expires_in' => $ttlSeconds];
    }

    /**
     * @return array<string, mixed>
     */
    public function read(#[SensitiveParameter] string $token, string $purpose): array
    {
        try {
            $encrypted = $this->repository()->get($this->key($token));
        } catch (Throwable $exception) {
            throw new RuntimeException('Authentication transaction storage is unavailable.', 0, $exception);
        }

        return $this->decode($encrypted, $purpose);
    }

    /**
     * @return array<string, mixed>
     */
    public function consume(#[SensitiveParameter] string $token, string $purpose): array
    {
        try {
            $encrypted = $this->repository()->pull($this->key($token));
        } catch (Throwable $exception) {
            throw new RuntimeException('Authentication transaction storage is unavailable.', 0, $exception);
        }

        return $this->decode($encrypted, $purpose);
    }

    public function forget(#[SensitiveParameter] string $token): void
    {
        try {
            $this->repository()->forget($this->key($token));
        } catch (Throwable $exception) {
            throw new RuntimeException('Authentication transaction storage is unavailable.', 0, $exception);
        }
    }

    private function repository(): \Illuminate\Contracts\Cache\Repository
    {
        $store = config('v2_identity.transactions.store');
        if (! is_string($store) || $store === '') {
            throw new RuntimeException('Authentication transaction storage is unavailable.');
        }

        return $this->cache->store($store);
    }

    private function key(#[SensitiveParameter] string $token): string
    {
        return 'oripa:v2:auth:transaction:'.$this->tokens->hash($token);
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(mixed $encrypted, string $purpose): array
    {
        if (! is_string($encrypted)) {
            throw new RuntimeException('Authentication transaction is invalid or expired.');
        }

        try {
            $decoded = json_decode(Crypt::decryptString($encrypted), true, flags: JSON_THROW_ON_ERROR);
        } catch (Throwable $exception) {
            throw new RuntimeException('Authentication transaction is invalid or expired.', 0, $exception);
        }
        if (
            ! is_array($decoded)
            || ($decoded['purpose'] ?? null) !== $purpose
            || ! is_array($decoded['payload'] ?? null)
        ) {
            throw new RuntimeException('Authentication transaction is invalid or expired.');
        }

        return $decoded['payload'];
    }
}
