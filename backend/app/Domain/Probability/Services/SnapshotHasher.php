<?php

namespace App\Domain\Probability\Services;

class SnapshotHasher
{
    public function hash(array $normalizedStages): string
    {
        return hash('sha256', json_encode(
            $this->sortRecursively($normalizedStages),
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        ));
    }

    private function sortRecursively(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (! array_is_list($value)) {
            ksort($value);
        }

        foreach ($value as $key => $child) {
            $value[$key] = $this->sortRecursively($child);
        }

        return $value;
    }
}
