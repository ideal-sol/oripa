<?php

namespace App\Domain\Catalog\Services;

use Illuminate\Support\Facades\DB;

final class V2GachaPublicCodeGenerator
{
    private const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789';

    /** @param null|callable(): string $candidate */
    public function unique(?callable $candidate = null): string
    {
        for ($attempt = 0; $attempt < 16; $attempt++) {
            $code = $candidate === null ? $this->randomCode() : $candidate();
            if (! preg_match('/\A[A-Za-z0-9]{11}\z/', $code)) {
                throw new \InvalidArgumentException('Invalid Gacha public code candidate.');
            }
            if (! DB::table('catalog_gachas')->where('public_code', $code)->exists()) {
                return $code;
            }
        }

        throw new \RuntimeException('Unable to allocate a unique Gacha public code.');
    }

    private function randomCode(): string
    {
        $code = '';
        $last = strlen(self::ALPHABET) - 1;
        for ($index = 0; $index < 11; $index++) {
            $code .= self::ALPHABET[random_int(0, $last)];
        }

        return $code;
    }
}
