<?php

namespace App\Domain\ContentContact\Services;

use App\Domain\ContentContact\Exceptions\V2ContentContactException;

final class V2ContentCursor
{
    public function encode(int $id): string
    {
        return rtrim(strtr(base64_encode('v2-content:'.$id), '+/', '-_'), '=');
    }

    public function decode(?string $cursor): ?int
    {
        if ($cursor === null || $cursor === '') {
            return null;
        }
        if (strlen($cursor) > 128 || ! preg_match('/\A[A-Za-z0-9_-]+\z/', $cursor)) {
            throw $this->invalid();
        }
        $decoded = base64_decode(strtr($cursor, '-_', '+/'), true);
        if (! is_string($decoded) || ! preg_match('/\Av2-content:([1-9][0-9]*)\z/', $decoded, $matches)) {
            throw $this->invalid();
        }

        return (int) $matches[1];
    }

    private function invalid(): V2ContentContactException
    {
        return new V2ContentContactException(
            'CONTENT_CURSOR_INVALID',
            422,
            'The Content cursor is invalid.'
        );
    }
}
