<?php

namespace App\Support;

use RuntimeException;
use SensitiveParameter;

final class V2HmacKeyring
{
    public function activeHash(
        string $activeConfig,
        #[SensitiveParameter] string $value,
        string $label
    ): string {
        return hash_hmac('sha256', $value, $this->key(config($activeConfig), $label));
    }

    /** @return list<string> */
    public function hashes(
        string $activeConfig,
        string $previousConfig,
        #[SensitiveParameter] string $value,
        string $label
    ): array {
        $encodedKeys = config($previousConfig, []);
        if (! is_array($encodedKeys)) {
            throw new RuntimeException($label.' previous keys are invalid.');
        }
        $keys = [$this->key(config($activeConfig), $label)];
        foreach ($encodedKeys as $encoded) {
            $key = $this->key($encoded, $label.' previous key');
            if (! in_array($key, $keys, true)) {
                $keys[] = $key;
            }
        }

        return array_map(
            fn (string $key): string => hash_hmac('sha256', $value, $key),
            $keys
        );
    }

    private function key(mixed $encoded, string $label): string
    {
        if (! is_string($encoded) || ! str_starts_with($encoded, 'base64:')) {
            throw new RuntimeException($label.' is unavailable.');
        }
        $key = base64_decode(substr($encoded, 7), true);
        if (! is_string($key) || strlen($key) < 32) {
            throw new RuntimeException($label.' is invalid.');
        }

        return $key;
    }
}
