<?php

namespace App\Domain\Catalog\Exceptions;

use RuntimeException;

final class V2CatalogException extends RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        public readonly int $status,
        string $message
    ) {
        parent::__construct($message);
    }
}
