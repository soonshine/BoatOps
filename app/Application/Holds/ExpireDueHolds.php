<?php

namespace App\Application\Holds;

use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

class ExpireDueHolds
{
    public function __construct(private readonly ExpireDueHoldAction $expireDueHold) {}

    public function execute(CarbonImmutable $asOf, int $batchSize = 500): int
    {
        if ($batchSize < 1) {
            throw new InvalidArgumentException('The HOLD expiry batch size must be at least one.');
        }

        $asOf = $asOf->utc();
        $expiredCount = 0;

        while (true) {
            $holdIds = $this->dueHoldIds($asOf, $batchSize);
            if ($holdIds->isEmpty()) {
                return $expiredCount;
            }

            $batchExpiredCount = 0;
            foreach ($holdIds as $holdId) {
                $result = $this->expireDueHold->execute((int) $holdId, $asOf, HoldActor::system());
                if (($result->payload['code'] ?? null) === 'INVENTORY_INTEGRITY_FAILED') {
                    throw new RuntimeException('HOLD expiry stopped because inventory integrity requires manual action.');
                }
                if ($result->changed) {
                    $batchExpiredCount++;
                    $expiredCount++;
                }
            }

            if ($batchExpiredCount > 0) {
                continue;
            }

            $authoritativeIds = $this->dueHoldIds($asOf, $batchSize);
            if ($authoritativeIds->isEmpty()) {
                return $expiredCount;
            }

            if ($authoritativeIds->all() === $holdIds->all()) {
                throw new RuntimeException('HOLD expiry made no progress while due HOLDs remain.');
            }
        }
    }

    /** @return Collection<int, int> */
    private function dueHoldIds(CarbonImmutable $asOf, int $batchSize): Collection
    {
        return DB::table('holds')->where('status', 'ACTIVE')->where('expires_at', '<=', $asOf)
            ->orderBy('id')->limit($batchSize)->pluck('id');
    }
}
