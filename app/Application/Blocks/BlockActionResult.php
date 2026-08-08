<?php

namespace App\Application\Blocks;

final readonly class BlockActionResult
{
    /** @param array<string, mixed> $payload */
    public function __construct(
        public int $status,
        public array $payload,
        public bool $changed = false,
    ) {}
}
