<?php

namespace App\Domain\Identity\Services;

class V2AgencyIdentifierGenerator
{
    public function loginId(): string
    {
        return str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    }

    public function advertisingCode(): string
    {
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789';
        $code = '';
        for ($position = 0; $position < 8; $position++) {
            $code .= $alphabet[random_int(0, 61)];
        }

        return $code;
    }
}
