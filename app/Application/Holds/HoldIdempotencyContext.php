<?php

namespace App\Application\Holds;

final readonly class HoldIdempotencyContext
{
    public function __construct(
        public string $operation,
        public string $key,
        public string $requestHash,
    ) {}
}
