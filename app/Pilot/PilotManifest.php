<?php

namespace App\Pilot;

use DateTimeImmutable;
use DateTimeZone;
use JsonException;

final readonly class PilotManifest
{
    /**
     * @param  list<array{name: string, buffer_before_minutes: int, buffer_after_minutes: int}>  $boats
     * @param  list<array{code: string, name: string}>  $tripTemplates
     * @param  list<array{code: string, name: string, service_start_time: string, service_end_time: string, duration_minutes: int, operating_time_status: string, applicable_boats: list<string>}>  $slots
     * @param  list<array{first_slot_code: string, second_slot_code: string, policy: string, reason: ?string}>  $compatibility
     * @param  array{can_calendar_read: bool, can_booking_workflow: bool, can_block: bool}  $operatorPermissions
     */
    private function __construct(
        public int $version,
        public string $organizationName,
        public string $organizationTimezone,
        public array $boats,
        public array $tripTemplates,
        public array $slots,
        public array $compatibility,
        public int $holdTtlMinutes,
        public string $operatorName,
        public string $operatorEmail,
        public array $operatorPermissions,
    ) {}

    public static function fromJsonFile(string $path): self
    {
        if (! is_file($path) || ! is_readable($path)) {
            throw PilotProvisioningException::invalidManifest("Manifest file is not readable: {$path}");
        }

        $json = file_get_contents($path);

        if ($json === false) {
            throw PilotProvisioningException::invalidManifest("Manifest file could not be read: {$path}");
        }

        try {
            $manifest = json_decode($json, true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw PilotProvisioningException::invalidManifest('Manifest is not valid JSON: '.$exception->getMessage());
        }

        return self::fromArray($manifest);
    }

    public static function fromArray(mixed $manifest): self
    {
        $manifest = self::object($manifest, 'manifest');
        self::keys(
            $manifest,
            ['version', 'organization', 'boats', 'trip_templates', 'slots', 'compatibility', 'hold_ttl_minutes', 'operator'],
            [],
            'manifest',
        );

        $version = self::integer($manifest['version'], 'version', 1, 1);
        $organization = self::object($manifest['organization'], 'organization');
        self::keys($organization, ['name', 'timezone'], [], 'organization');
        $organizationName = self::string($organization['name'], 'organization.name');
        $organizationTimezone = self::string($organization['timezone'], 'organization.timezone');

        if (! in_array($organizationTimezone, DateTimeZone::listIdentifiers(DateTimeZone::ALL_WITH_BC), true)) {
            throw PilotProvisioningException::invalidManifest('organization.timezone is not a recognized IANA timezone.');
        }

        $boats = [];
        $boatNames = [];

        foreach (self::nonEmptyList($manifest['boats'], 'boats') as $index => $boatValue) {
            $path = "boats.{$index}";
            $boat = self::object($boatValue, $path);
            self::keys($boat, ['name', 'buffer_before_minutes', 'buffer_after_minutes'], [], $path);
            $name = self::string($boat['name'], "{$path}.name");

            if (isset($boatNames[$name])) {
                throw PilotProvisioningException::invalidManifest("Duplicate boat identity: {$name}");
            }

            $boatNames[$name] = true;
            $boats[] = [
                'name' => $name,
                'buffer_before_minutes' => self::integer($boat['buffer_before_minutes'], "{$path}.buffer_before_minutes", 0, 1440),
                'buffer_after_minutes' => self::integer($boat['buffer_after_minutes'], "{$path}.buffer_after_minutes", 0, 1440),
            ];
        }

        $tripTemplates = [];
        $tripTemplateCodes = [];

        foreach (self::nonEmptyList($manifest['trip_templates'], 'trip_templates') as $index => $templateValue) {
            $path = "trip_templates.{$index}";
            $template = self::object($templateValue, $path);
            self::keys($template, ['code', 'name'], [], $path);
            $code = self::code($template['code'], "{$path}.code");

            if (isset($tripTemplateCodes[$code])) {
                throw PilotProvisioningException::invalidManifest("Duplicate trip template identity: {$code}");
            }

            $tripTemplateCodes[$code] = true;
            $tripTemplates[] = [
                'code' => $code,
                'name' => self::string($template['name'], "{$path}.name"),
            ];
        }

        $slots = [];
        $slotCodes = [];

        foreach (self::nonEmptyList($manifest['slots'], 'slots') as $index => $slotValue) {
            $path = "slots.{$index}";
            $slot = self::object($slotValue, $path);
            self::keys(
                $slot,
                ['code', 'name', 'service_start_time', 'service_end_time', 'duration_minutes', 'operating_time_status', 'applicable_boats'],
                [],
                $path,
            );
            $code = self::code($slot['code'], "{$path}.code");

            if (isset($slotCodes[$code])) {
                throw PilotProvisioningException::invalidManifest("Duplicate slot identity: {$code}");
            }

            $slotCodes[$code] = true;
            $start = self::time($slot['service_start_time'], "{$path}.service_start_time");
            $end = self::time($slot['service_end_time'], "{$path}.service_end_time");
            $duration = self::integer($slot['duration_minutes'], "{$path}.duration_minutes", 1, 1440);
            $startSecond = self::secondOfDay($start);
            $endSecond = self::secondOfDay($end);

            if ($endSecond <= $startSecond) {
                throw PilotProvisioningException::invalidManifest("{$path} crosses midnight; cross-midnight slots are not supported.");
            }

            if (($endSecond - $startSecond) !== $duration * 60) {
                throw PilotProvisioningException::invalidManifest("{$path}.duration_minutes does not match its service interval.");
            }

            $operatingTimeStatus = strtoupper(self::string($slot['operating_time_status'], "{$path}.operating_time_status"));

            if (! in_array($operatingTimeStatus, ['UNVERIFIED', 'DEMO_DEFAULT_UNVERIFIED', 'FICTIONAL_VALIDATION_SCENARIO', 'VERIFIED'], true)) {
                throw PilotProvisioningException::invalidManifest("{$path}.operating_time_status is invalid.");
            }

            $applicableBoats = [];

            foreach (self::nonEmptyList($slot['applicable_boats'], "{$path}.applicable_boats") as $boatIndex => $boatNameValue) {
                $boatName = self::string($boatNameValue, "{$path}.applicable_boats.{$boatIndex}");

                if (! isset($boatNames[$boatName])) {
                    throw PilotProvisioningException::invalidManifest("{$path} references unknown boat: {$boatName}");
                }

                if (in_array($boatName, $applicableBoats, true)) {
                    throw PilotProvisioningException::invalidManifest("{$path} repeats applicable boat: {$boatName}");
                }

                $applicableBoats[] = $boatName;
            }

            sort($applicableBoats);
            $slots[] = [
                'code' => $code,
                'name' => self::string($slot['name'], "{$path}.name"),
                'service_start_time' => $start,
                'service_end_time' => $end,
                'duration_minutes' => $duration,
                'operating_time_status' => $operatingTimeStatus,
                'applicable_boats' => $applicableBoats,
            ];
        }

        $compatibility = [];
        $compatibilityPairs = [];

        foreach (self::list($manifest['compatibility'], 'compatibility') as $index => $ruleValue) {
            $path = "compatibility.{$index}";
            $rule = self::object($ruleValue, $path);
            self::keys($rule, ['first_slot_code', 'second_slot_code', 'policy'], ['reason'], $path);
            $firstCode = self::code($rule['first_slot_code'], "{$path}.first_slot_code");
            $secondCode = self::code($rule['second_slot_code'], "{$path}.second_slot_code");

            if ($firstCode === $secondCode) {
                throw PilotProvisioningException::invalidManifest("{$path} must reference two different slots.");
            }

            if (! isset($slotCodes[$firstCode]) || ! isset($slotCodes[$secondCode])) {
                throw PilotProvisioningException::invalidManifest("{$path} references an unknown slot.");
            }

            $pair = [$firstCode, $secondCode];
            sort($pair);
            $pairKey = implode(':', $pair);

            if (isset($compatibilityPairs[$pairKey])) {
                throw PilotProvisioningException::invalidManifest("Duplicate compatibility identity: {$pairKey}");
            }

            $compatibilityPairs[$pairKey] = true;
            $policy = strtoupper(self::string($rule['policy'], "{$path}.policy"));

            if (! in_array($policy, ['ALLOW', 'DENY'], true)) {
                throw PilotProvisioningException::invalidManifest("{$path}.policy must be ALLOW or DENY.");
            }

            $reason = array_key_exists('reason', $rule) && $rule['reason'] !== null
                ? self::string($rule['reason'], "{$path}.reason", 500)
                : null;
            $compatibility[] = [
                'first_slot_code' => $pair[0],
                'second_slot_code' => $pair[1],
                'policy' => $policy,
                'reason' => $reason,
            ];
        }

        $operator = self::object($manifest['operator'], 'operator');
        self::keys($operator, ['name', 'email', 'permissions'], [], 'operator');
        $operatorName = self::string($operator['name'], 'operator.name');
        $operatorEmail = strtolower(self::string($operator['email'], 'operator.email'));

        if (filter_var($operatorEmail, FILTER_VALIDATE_EMAIL) === false) {
            throw PilotProvisioningException::invalidManifest('operator.email is invalid.');
        }

        $permissions = self::object($operator['permissions'], 'operator.permissions');
        self::keys($permissions, ['can_calendar_read', 'can_booking_workflow', 'can_block'], [], 'operator.permissions');

        foreach ($permissions as $permission => $value) {
            if (! is_bool($value)) {
                throw PilotProvisioningException::invalidManifest("operator.permissions.{$permission} must be boolean.");
            }
        }

        return new self(
            version: $version,
            organizationName: $organizationName,
            organizationTimezone: $organizationTimezone,
            boats: $boats,
            tripTemplates: $tripTemplates,
            slots: $slots,
            compatibility: $compatibility,
            holdTtlMinutes: self::integer($manifest['hold_ttl_minutes'], 'hold_ttl_minutes', 1, 1440),
            operatorName: $operatorName,
            operatorEmail: $operatorEmail,
            operatorPermissions: [
                'can_calendar_read' => $permissions['can_calendar_read'],
                'can_booking_workflow' => $permissions['can_booking_workflow'],
                'can_block' => $permissions['can_block'],
            ],
        );
    }

    /** @return array<string, mixed> */
    private static function object(mixed $value, string $path): array
    {
        if (! is_array($value) || (array_is_list($value) && $value !== [])) {
            throw PilotProvisioningException::invalidManifest("{$path} must be an object.");
        }

        return $value;
    }

    /** @return list<mixed> */
    private static function list(mixed $value, string $path): array
    {
        if (! is_array($value) || ! array_is_list($value)) {
            throw PilotProvisioningException::invalidManifest("{$path} must be a list.");
        }

        return $value;
    }

    /** @return non-empty-list<mixed> */
    private static function nonEmptyList(mixed $value, string $path): array
    {
        $value = self::list($value, $path);

        if ($value === []) {
            throw PilotProvisioningException::invalidManifest("{$path} must not be empty.");
        }

        return $value;
    }

    /**
     * @param  array<string, mixed>  $value
     * @param  list<string>  $required
     * @param  list<string>  $optional
     */
    private static function keys(array $value, array $required, array $optional, string $path): void
    {
        $keys = array_keys($value);
        $missing = array_values(array_diff($required, $keys));
        $unknown = array_values(array_diff($keys, [...$required, ...$optional]));

        if ($missing !== []) {
            throw PilotProvisioningException::invalidManifest("{$path} is missing key(s): ".implode(', ', $missing));
        }

        if ($unknown !== []) {
            throw PilotProvisioningException::invalidManifest("{$path} contains unknown key(s): ".implode(', ', $unknown));
        }
    }

    private static function string(mixed $value, string $path, int $maximumLength = 255): string
    {
        if (! is_string($value) || trim($value) === '' || mb_strlen(trim($value)) > $maximumLength) {
            throw PilotProvisioningException::invalidManifest("{$path} must be a non-empty string of at most {$maximumLength} characters.");
        }

        return trim($value);
    }

    private static function code(mixed $value, string $path): string
    {
        $code = strtoupper(self::string($value, $path, 100));

        if (! preg_match('/^[A-Z0-9][A-Z0-9_-]{1,99}$/', $code)) {
            throw PilotProvisioningException::invalidManifest("{$path} is invalid.");
        }

        return $code;
    }

    private static function integer(mixed $value, string $path, int $minimum, int $maximum): int
    {
        if (! is_int($value) || $value < $minimum || $value > $maximum) {
            throw PilotProvisioningException::invalidManifest("{$path} must be an integer between {$minimum} and {$maximum}.");
        }

        return $value;
    }

    private static function time(mixed $value, string $path): string
    {
        if (! is_string($value)) {
            throw PilotProvisioningException::invalidManifest("{$path} must use HH:MM:SS.");
        }

        $time = DateTimeImmutable::createFromFormat('!H:i:s', $value);
        $errors = DateTimeImmutable::getLastErrors();

        if (
            $time === false
            || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))
            || $time->format('H:i:s') !== $value
        ) {
            throw PilotProvisioningException::invalidManifest("{$path} must use HH:MM:SS.");
        }

        return $value;
    }

    private static function secondOfDay(string $time): int
    {
        [$hour, $minute, $second] = array_map('intval', explode(':', $time));

        return ($hour * 3600) + ($minute * 60) + $second;
    }
}
