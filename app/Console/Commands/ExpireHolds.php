<?php

namespace App\Console\Commands;

use App\Application\Holds\ExpireDueHolds;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

class ExpireHolds extends Command
{
    protected $signature = 'holds:expire';

    protected $description = 'Expire active HOLDs whose TTL has elapsed';

    public function handle(ExpireDueHolds $expireDueHolds): int
    {
        $expiredCount = $expireDueHolds->execute(CarbonImmutable::now('UTC'));

        $this->info("Expired {$expiredCount} HOLD(s).");

        return self::SUCCESS;
    }
}
