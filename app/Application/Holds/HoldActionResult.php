<?php

namespace App\Application\Holds;

final readonly class HoldActionResult
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public int $status,
        public array $payload,
        public bool $changed = false,
    ) {}
}
