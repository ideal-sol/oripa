<?php

namespace App\Domain\Identity\Exceptions;

use RuntimeException;

final class V2OidcProtocolException extends RuntimeException
{
    public function __construct(public readonly string $reasonCode)
    {
        parent::__construct('The external identity response was rejected.');
    }
}
