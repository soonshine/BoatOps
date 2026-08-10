<?php

namespace App\Application\Trips;

final readonly class TripActionResult
{
    /** @param array<string, mixed> $payload */
    public function __construct(
        public int $status,
        public array $payload,
        public bool $changed = false,
    ) {}
}
