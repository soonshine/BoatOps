<?php

namespace App\Application\Holds;

use Illuminate\Support\Facades\DB;

final class OrganizationHoldTtlPolicy
{
    public const KEY = 'inventory.hold_ttl_minutes';

    public function minutes(int $organizationId): ?int
    {
        $value = DB::table('organization_settings')
            ->where('organization_id', $organizationId)
            ->where('key', self::KEY)
            ->value('value');

        if (! is_string($value) || ! ctype_digit($value)) {
            return null;
        }

        $minutes = (int) $value;

        return $minutes > 0 ? $minutes : null;
    }
}
