<?php

namespace App\Domain\Audit\V2\Services;

use DateTimeInterface;
use JsonException;
use RuntimeException;
use SensitiveParameter;

final class V2AuditHasher
{
    public function activeKeyVersion(): string
    {
        $version = config('v2_audit.active_hmac_key_version');
        if (! is_string($version) || ! preg_match('/\A[a-zA-Z0-9._-]{1,32}\z/', $version)) {
            throw new RuntimeException('Audit HMAC key version is invalid.');
        }

        $this->key($version);

        return $version;
    }

    public function digest(array $payload, ?string $version = null): string
    {
        $version ??= $this->activeKeyVersion();

        return hash_hmac('sha256', $this->canonicalJson($payload), $this->key($version));
    }

    public function correlation(#[SensitiveParameter] string $value): string
    {
        if ($value === '') {
            throw new RuntimeException('Audit correlation input is empty.');
        }

        return $this->digest(['correlation' => $value]);
    }

    /**
     * @throws JsonException
     */
    public function canonicalJson(array $payload): string
    {
        return json_encode(
            $this->normalize($payload),
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        );
    }

    private function key(string $version): string
    {
        $encoded = config('v2_audit.hmac_keys.'.$version);
        if (! is_string($encoded) || ! str_starts_with($encoded, 'base64:')) {
            throw new RuntimeException('Audit HMAC key is unavailable.');
        }
        $key = base64_decode(substr($encoded, 7), true);
        if (! is_string($key) || strlen($key) < 32) {
            throw new RuntimeException('Audit HMAC key is invalid.');
        }

        return $key;
    }

    private function normalize(mixed $value): mixed
    {
        if ($value instanceof DateTimeInterface) {
            return $value->format('Y-m-d\TH:i:sP');
        }
        if (is_object($value)) {
            return $this->normalize(get_object_vars($value));
        }
        if (! is_array($value)) {
            return $value;
        }
        if (array_is_list($value)) {
            return array_map(fn (mixed $item): mixed => $this->normalize($item), $value);
        }
        ksort($value, SORT_STRING);
        foreach ($value as $key => $item) {
            $value[$key] = $this->normalize($item);
        }

        return $value;
    }
}
