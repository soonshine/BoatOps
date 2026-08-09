<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\ConfigurationUrlParser;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use InvalidArgumentException;
use RuntimeException;

final class OperatorDemoSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment('production')
            || config('demo_site.enabled') !== true
            || config('demo_site.mode') !== 'isolated_operator_demo'
            || config('demo_site.isolated_dataset') !== true
            || ! $this->configuredDatabaseIsSqlite()) {
            throw new RuntimeException('OperatorDemoSeeder requires the production isolated_operator_demo mode with the approved isolated SQLite database.');
        }

        $email = strtolower(trim((string) getenv('BOATOPS_DEMO_OPERATOR_EMAIL')));
        $password = (string) getenv('BOATOPS_DEMO_OPERATOR_PASSWORD');

        if (! str_ends_with($email, '@example.test')) {
            throw new RuntimeException('BOATOPS_DEMO_OPERATOR_EMAIL must use the reserved @example.test domain.');
        }
        if (strlen($password) < 24) {
            throw new RuntimeException('BOATOPS_DEMO_OPERATOR_PASSWORD must be at least 24 characters.');
        }

        $organizations = DB::table('organizations')
            ->where('name', config('demo_site.organization_name'))
            ->limit(2)
            ->get();
        if ($organizations->count() !== 1) {
            throw new RuntimeException('The isolated fictional Demo organization must resolve exactly once.');
        }
        $organizationId = (int) $organizations->first()->id;

        DB::transaction(function () use ($organizationId, $email, $password): void {
            $user = User::query()->where('email', $email)->first();
            if ($user !== null && $user->name !== 'Fictional Demo Operator') {
                throw new RuntimeException('The configured fictional operator email is already used by a non-Demo user.');
            }

            if ($user === null) {
                $user = User::query()->create([
                    'name' => 'Fictional Demo Operator',
                    'email' => $email,
                    'password' => Hash::make($password),
                ]);
            } else {
                $user->forceFill(['password' => Hash::make($password)])->save();
            }

            $membership = DB::table('operator_memberships')->where('user_id', $user->id)->first();
            if ($membership !== null && (int) $membership->organization_id !== $organizationId) {
                throw new RuntimeException('The fictional Demo operator is already attached to another organization.');
            }

            DB::table('operator_memberships')->updateOrInsert(
                ['user_id' => $user->id],
                [
                    'organization_id' => $organizationId,
                    'status' => 'ACTIVE',
                    'can_calendar_read' => true,
                    'can_booking_workflow' => true,
                    'can_block' => true,
                    'created_at' => $membership?->created_at ?? now(),
                    'updated_at' => now(),
                ],
            );
        }, 3);
    }

    private function configuredDatabaseIsSqlite(): bool
    {
        $connection = (string) config('database.default');
        $configuration = config("database.connections.{$connection}");
        if (! is_array($configuration)) {
            return false;
        }

        try {
            $configuration = (new ConfigurationUrlParser)->parseConfiguration($configuration);
        } catch (InvalidArgumentException) {
            return false;
        }

        foreach (['read', 'write', 'direct'] as $override) {
            if (array_key_exists($override, $configuration) && ! is_array($configuration[$override])) {
                return false;
            }
        }

        return ($configuration['driver'] ?? null) === 'sqlite'
            && $this->allConfiguredDriversAreSqlite($configuration);
    }

    private function allConfiguredDriversAreSqlite(array $configuration): bool
    {
        foreach ($configuration as $key => $value) {
            if ($key === 'driver' && $value !== 'sqlite') {
                return false;
            }
            if (is_array($value) && ! $this->allConfiguredDriversAreSqlite($value)) {
                return false;
            }
        }

        return true;
    }
}
