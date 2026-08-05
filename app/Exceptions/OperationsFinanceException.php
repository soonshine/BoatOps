<?php

namespace App\Exceptions;

use RuntimeException;

class OperationsFinanceException extends RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int $httpStatus = 409,
        public readonly bool $manualActionRequired = true,
    ) {
        parent::__construct($message);
    }
}
