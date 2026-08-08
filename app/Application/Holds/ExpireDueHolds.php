<?php

namespace App\Application\Holds;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class ExpireDueHolds
{
    public function __construct(private readonly ExpireDueHoldAction $expireDueHold) {}

    public function execute(CarbonImmutable $asOf, int $limit = 500): int
    {
        $holdIds = DB::table('holds')->where('status', 'ACTIVE')->where('expires_at', '<=', $asOf)
            ->orderBy('id')->limit($limit)->pluck('id');
        $expiredCount = 0;
        foreach ($holdIds as $holdId) {
            $result = $this->expireDueHold->execute((int) $holdId, $asOf, HoldActor::system());
            if ($result->changed) {
                $expiredCount++;
            }
        }

        return $expiredCount;
    }
}
