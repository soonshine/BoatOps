<?php

namespace App\Exceptions;

use RuntimeException;

class SlotCatalogException extends RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int $httpStatus = 409,
        public readonly bool $manualActionRequired = false,
    ) {
        parent::__construct($message);
    }
}
