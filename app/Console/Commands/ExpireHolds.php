<?php

namespace App\Console\Commands;

use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ExpireHolds extends Command
{
    protected $signature = 'holds:expire';

    protected $description = 'Expire active HOLDs whose TTL has elapsed';

    public function handle(): int
    {
        $now = CarbonImmutable::now('UTC');
        $holdIds = DB::table('holds')
            ->where('status', 'ACTIVE')
            ->where('expires_at', '<=', $now)
            ->orderBy('id')
            ->pluck('id');
        $expiredCount = 0;

        foreach ($holdIds as $holdId) {
            $expired = DB::transaction(function () use ($holdId, $now): bool {
                $candidate = DB::table('holds')->where('id', $holdId)->first();

                if (! $candidate) {
                    return false;
                }

                DB::table('organizations')
                    ->where('id', $candidate->organization_id)
                    ->lockForUpdate()
                    ->first();
                $hold = DB::table('holds')->where('id', $holdId)->lockForUpdate()->first();

                if (! $hold || $hold->status !== 'ACTIVE') {
                    return false;
                }

                if (CarbonImmutable::parse($hold->expires_at)->utc()->greaterThan($now)) {
                    return false;
                }

                DB::table('holds')->where('id', $hold->id)->update([
                    'status' => 'EXPIRED',
                    'updated_at' => $now,
                ]);
                DB::table('allocations')->where('id', $hold->allocation_id)->update([
                    'status' => 'EXPIRED',
                    'updated_at' => $now,
                ]);
                DB::table('organizations')->where('id', $hold->organization_id)->increment('inventory_revision');
                $revision = (int) DB::table('organizations')
                    ->where('id', $hold->organization_id)
                    ->value('inventory_revision');
                $eventPayload = [
                    'event_id' => (string) Str::uuid(),
                    'event_type' => 'hold.expired.v1',
                    'event_version' => 1,
                    'occurred_at' => $now->format('Y-m-d\TH:i:s\Z'),
                    'organization_id' => $hold->organization_id,
                    'aggregate_type' => 'hold',
                    'aggregate_id' => $hold->id,
                    'inventory_revision' => $revision,
                    'external_reference' => $hold->external_reference,
                    'status' => 'EXPIRED',
                ];
                DB::table('outbox_events')->insert([
                    'event_id' => $eventPayload['event_id'],
                    'organization_id' => $hold->organization_id,
                    'event_type' => $eventPayload['event_type'],
                    'aggregate_type' => 'hold',
                    'aggregate_id' => $hold->id,
                    'inventory_revision' => $revision,
                    'payload' => json_encode($eventPayload, JSON_THROW_ON_ERROR),
                    'occurred_at' => $now,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
                DB::table('audit_logs')->insert([
                    'organization_id' => $hold->organization_id,
                    'actor_type' => 'system',
                    'actor_id' => null,
                    'action' => 'hold.expired',
                    'object_type' => 'hold',
                    'object_id' => $hold->id,
                    'before_values' => json_encode(['status' => 'ACTIVE'], JSON_THROW_ON_ERROR),
                    'after_values' => json_encode(['status' => 'EXPIRED'], JSON_THROW_ON_ERROR),
                    'reason' => 'HOLD_TTL_ELAPSED',
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);

                return true;
            }, 3);

            if ($expired) {
                $expiredCount++;
            }
        }

        $this->info("Expired {$expiredCount} HOLD(s).");

        return self::SUCCESS;
    }
}
