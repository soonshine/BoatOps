<?php

namespace App\Application\Bookings;

final readonly class BookingActionResult
{
    /** @param array<string, mixed> $payload */
    public function __construct(
        public int $status,
        public array $payload,
        public bool $changed = false,
    ) {}
}
