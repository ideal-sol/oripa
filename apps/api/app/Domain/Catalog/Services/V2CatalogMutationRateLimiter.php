<?php

namespace App\Domain\Catalog\Services;

use App\Domain\Identity\Exceptions\V2AuthenticationException;
use Illuminate\Cache\RateLimiter;
use RuntimeException;
use SensitiveParameter;
use Throwable;

final class V2CatalogMutationRateLimiter
{
    public function __construct(private readonly RateLimiter $limiter)
    {
    }

    public function assertAdmin(#[SensitiveParameter] string $adminPublicId): void
    {
        $limit = config('v2_catalog.mutation.rate_limit');
        if (
            ! is_array($limit)
            || count($limit) !== 2
            || ! is_int($limit[0])
            || ! is_int($limit[1])
            || $limit[0] !== 30
            || $limit[1] !== 600
        ) {
            throw new RuntimeException('Catalog mutation rate limit configuration is invalid.');
        }
        $key = 'catalog_master_mutation:admin:'.$this->opaque($adminPublicId);
        try {
            if ($this->limiter->tooManyAttempts($key, $limit[0])) {
                throw new V2AuthenticationException(
                    'RATE_LIMITED',
                    429,
                    'Too many Catalog mutation attempts.',
                    true,
                    max(1, $this->limiter->availableIn($key))
                );
            }
            $this->limiter->hit($key, $limit[1]);
        } catch (V2AuthenticationException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw new V2AuthenticationException(
                'AUTH_SERVICE_UNAVAILABLE',
                503,
                'The authorization service is temporarily unavailable.',
                true,
                30
            );
        }
    }

    private function opaque(#[SensitiveParameter] string $value): string
    {
        $key = (string) config('app.key');
        if ($key === '') {
            throw new RuntimeException('Catalog mutation correlation key is unavailable.');
        }

        return hash_hmac('sha256', $value, $key);
    }
}
