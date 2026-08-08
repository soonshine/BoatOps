<?php

namespace App\Application\Holds;

use InvalidArgumentException;

final readonly class HoldActor
{
    private function __construct(
        public string $type,
        public ?int $id,
    ) {
        if (! in_array($type, ['api_client', 'operator_user', 'system'], true)) {
            throw new InvalidArgumentException('Unsupported HOLD actor type.');
        }

        if ($type === 'system' && $id !== null) {
            throw new InvalidArgumentException('The system HOLD actor cannot have an actor ID.');
        }

        if ($type !== 'system' && $id === null) {
            throw new InvalidArgumentException('A non-system HOLD actor requires an actor ID.');
        }
    }

    public static function apiClient(int $id): self
    {
        return new self('api_client', $id);
    }

    public static function operatorUser(int $id): self
    {
        return new self('operator_user', $id);
    }

    public static function system(): self
    {
        return new self('system', null);
    }
}
