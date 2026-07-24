<?php

namespace App\Domain\Identity\Services;

use App\Domain\Identity\Exceptions\V2AuthenticationException;
use Illuminate\Cache\RateLimiter;
use RuntimeException;
use SensitiveParameter;
use Throwable;

final class V2RateLimiter
{
    public function __construct(private readonly RateLimiter $limiter)
    {
    }

    public function assertGlobal(string $name, string $ip): void
    {
        $this->assertNotLimited($name.':ip:'.$this->opaque($ip), $name);
        $this->hit($name.':ip:'.$this->opaque($ip), $name);
    }

    public function assertAccount(string $name, #[SensitiveParameter] string $account, string $ip): void
    {
        $key = $name.':account:'.$this->opaque($account).'|'.$this->opaque($ip);
        $this->assertNotLimited($key, $name);
    }

    public function hitAccount(string $name, #[SensitiveParameter] string $account, string $ip): void
    {
        $this->hit($name.':account:'.$this->opaque($account).'|'.$this->opaque($ip), $name);
    }

    public function assertSubject(string $name, #[SensitiveParameter] string $subject): void
    {
        $key = $name.':subject:'.$this->opaque($subject);
        $this->assertNotLimited($key, $name);
        $this->hit($key, $name);
    }

    private function assertNotLimited(string $key, string $name): void
    {
        [$maximum] = $this->limits($name);
        try {
            if (! $this->limiter->tooManyAttempts($key, $maximum)) {
                return;
            }
            $retryAfter = max(1, $this->limiter->availableIn($key));
        } catch (Throwable $exception) {
            throw new V2AuthenticationException(
                'AUTH_SERVICE_UNAVAILABLE',
                503,
                'The authentication service is temporarily unavailable.',
                true,
                30
            );
        }

        throw new V2AuthenticationException(
            'RATE_LIMITED',
            429,
            'Too many authentication attempts.',
            true,
            $retryAfter
        );
    }

    private function hit(string $key, string $name): void
    {
        [, $decay] = $this->limits($name);
        try {
            $this->limiter->hit($key, $decay);
        } catch (Throwable $exception) {
            throw new V2AuthenticationException(
                'AUTH_SERVICE_UNAVAILABLE',
                503,
                'The authentication service is temporarily unavailable.',
                true,
                30
            );
        }
    }

    /**
     * @return array{int, int}
     */
    private function limits(string $name): array
    {
        $value = config('v2_identity.rate_limits.'.$name);
        if (
            ! is_array($value)
            || count($value) !== 2
            || ! is_int($value[0])
            || ! is_int($value[1])
            || $value[0] < 1
            || $value[1] < 1
        ) {
            throw new RuntimeException('Authentication rate limit configuration is invalid.');
        }

        return [$value[0], $value[1]];
    }

    private function opaque(#[SensitiveParameter] string $value): string
    {
        $key = (string) config('app.key');
        if ($key === '') {
            throw new RuntimeException('Authentication rate limit key is unavailable.');
        }

        return hash_hmac('sha256', $value, $key);
    }
}
