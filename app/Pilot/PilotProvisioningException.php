<?php

namespace App\Pilot;

use RuntimeException;

final class PilotProvisioningException extends RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        string $message,
    ) {
        parent::__construct($message);
    }

    public static function invalidManifest(string $message): self
    {
        return new self('INVALID_MANIFEST', $message);
    }

    public static function configurationDrift(string $message): self
    {
        return new self('CONFIGURATION_DRIFT', $message);
    }
}
