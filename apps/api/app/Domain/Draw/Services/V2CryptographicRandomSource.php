<?php

namespace App\Domain\Draw\Services;

use Closure;

final class V2CryptographicRandomSource
{
    public function __construct(private readonly ?Closure $testGenerator = null)
    {
    }

    public function integer(int $minimum, int $maximum): int
    {
        if ($this->testGenerator !== null) {
            $value = ($this->testGenerator)($minimum, $maximum);
            if (! is_int($value) || $value < $minimum || $value > $maximum) {
                throw new \LogicException('Test Random Source returned an invalid value.');
            }

            return $value;
        }

        return random_int($minimum, $maximum);
    }
}
