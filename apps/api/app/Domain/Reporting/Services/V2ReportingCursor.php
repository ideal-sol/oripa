<?php

namespace App\Domain\Reporting\Services;

use App\Domain\Reporting\Exceptions\V2ReportingException;

final class V2ReportingCursor
{
    public function encode(int $id): string
    {
        return rtrim(strtr(base64_encode('v1:'.$id), '+/', '-_'), '=');
    }

    public function decode(?string $cursor): ?int
    {
        if ($cursor === null || $cursor === '') {
            return null;
        }
        $decoded = base64_decode(
            strtr($cursor, '-_', '+/').str_repeat('=', (4 - strlen($cursor) % 4) % 4),
            true
        );
        if (! is_string($decoded) || ! preg_match('/\Av1:([1-9][0-9]*)\z/', $decoded, $match)) {
            throw new V2ReportingException(
                'REPORTING_CURSOR_INVALID',
                422,
                'The Reporting cursor is invalid.'
            );
        }

        return (int) $match[1];
    }
}
