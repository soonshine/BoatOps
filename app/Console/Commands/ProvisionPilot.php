<?php

namespace App\Console\Commands;

use App\Pilot\PilotManifest;
use App\Pilot\PilotProvisioningException;
use App\Pilot\PilotProvisioningService;
use Illuminate\Console\Command;

final class ProvisionPilot extends Command
{
    protected $signature = 'pilot:provision
        {manifest : Path to a version 1 pilot manifest}
        {--validate : Apply strict version 1 manifest validation before provisioning}';

    protected $description = 'Transactionally provision or verify one BoatOps pilot manifest';

    public function handle(PilotProvisioningService $provisioning): int
    {
        try {
            $manifest = PilotManifest::fromJsonFile((string) $this->argument('manifest'));
            $password = getenv('BOATOPS_PILOT_OPERATOR_PASSWORD');
            $result = $provisioning->provision(
                $manifest,
                is_string($password) && $password !== '' ? $password : null,
            );
        } catch (PilotProvisioningException $exception) {
            $this->error($exception->errorCode);
            $this->line($exception->getMessage());

            return self::FAILURE;
        }

        $this->info($result);

        return self::SUCCESS;
    }
}
