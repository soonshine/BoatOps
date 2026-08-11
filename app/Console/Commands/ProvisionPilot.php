<?php

namespace App\Console\Commands;

use App\Application\Pilot\PilotProvisioningManifest;
use App\Application\Pilot\ProvisionPilot as ProvisionPilotService;
use Illuminate\Console\Command;
use Throwable;

final class ProvisionPilot extends Command
{
    protected $signature = 'pilot:provision
        {manifest : Path to the versioned non-secret Pilot provisioning manifest}
        {--validate : Validate the manifest and current configuration without writing}';

    protected $description = 'Provision or validate one bounded BoatOps Pilot configuration';

    public function handle(ProvisionPilotService $provisionPilot): int
    {
        try {
            $manifest = PilotProvisioningManifest::fromPath((string) $this->argument('manifest'));
            $secret = getenv(ProvisionPilotService::OPERATOR_PASSWORD_ENV);
            $password = is_string($secret) && $secret !== '' ? $secret : null;
            $receipt = $provisionPilot->execute(
                manifest: $manifest,
                operatorPassword: $password,
                validateOnly: (bool) $this->option('validate'),
            );
            $this->line(json_encode($receipt, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
    }
}
