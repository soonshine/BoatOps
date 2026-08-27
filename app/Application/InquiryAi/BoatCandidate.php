<?php

namespace App\Application\InquiryAi;

/**
 * One organization-visible Boat as a deterministic resolution candidate
 * (#54 / 51B). This is a plain value object: it carries no database identity
 * authority and creates no business schema. `name` is the organization's
 * Boat truth name; `id` is the real Boat row id only used after an exact
 * deterministic match.
 */
final readonly class BoatCandidate
{
    public function __construct(
        public int $id,
        public string $name,
    ) {}
}
