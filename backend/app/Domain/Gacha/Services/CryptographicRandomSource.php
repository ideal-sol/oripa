<?php

namespace App\Domain\Gacha\Services;

class CryptographicRandomSource
{
    public function integer(int $minimum, int $maximum): int
    {
        return random_int($minimum, $maximum);
    }
}
