<?php

namespace App\Application\Pilot;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use JsonException;

final class PilotProvisioningManifest
{
    /** @param array<string, mixed> $data */
    private function __construct(private readonly array $data) {}

    public static function fromPath(string $path): self
    {
        if (! is_file($path) || ! is_readable($path)) {
            throw new InvalidArgumentException('MANIFEST_INVALID: manifest file is not readable.');
        }

        $json = file_get_contents($path);
        if ($json === false) {
            throw new InvalidArgumentException('MANIFEST_INVALID: manifest file could not be read.');
        }

        try {
            $data = json_decode($json, true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new InvalidArgumentException('MANIFEST_INVALID: invalid JSON.', 0, $e);
        }

        if (! is_array($data) || array_is_list($data)) {
            throw new InvalidArgumentException('MANIFEST_INVALID: root must be a JSON object.');
        }

        return self::fromArray($data);
    }

    /** @param array<string, mixed> $input */
    public static function fromArray(array $input): self
    {
        self::assertNoSecrets($input);

        $version = self::requiredString($input, 'version');
        if ($version !== 'v1') {
            throw new InvalidArgumentException('MANIFEST_INVALID: version must be v1.');
        }

        $organizationInput = self::requiredObject($input, 'organization');
        $organization = [
            'name' => self::requiredString($organizationInput, 'name'),
            'timezone' => self::requiredString($organizationInput, 'timezone'),
        ];
        if (! in_array($organization['timezone'], DateTimeZone::listIdentifiers(), true)) {
            throw new InvalidArgumentException('MANIFEST_INVALID: organization.timezone is invalid.');
        }

        $boats = [];
        $boatNames = [];
        foreach (self::requiredList($input, 'boats') as $index => $row) {
            if (! is_array($row) || array_is_list($row)) {
                throw new InvalidArgumentException("MANIFEST_INVALID: boats.{$index} must be an object.");
            }
            $name = self::requiredString($row, 'name');
            if (isset($boatNames[$name])) {
                throw new InvalidArgumentException("MANIFEST_INVALID: duplicate boat name {$name}.");
            }
            $boatNames[$name] = true;
            $safeLimit = $row['safe_max_party_size_or_sop_limit'] ?? null;
            if ($safeLimit !== null && ! is_int($safeLimit) && ! is_string($safeLimit)) {
                throw new InvalidArgumentException('MANIFEST_INVALID: safe_max_party_size_or_sop_limit must be a string or integer.');
            }
            if (is_string($safeLimit) && trim($safeLimit) === '') {
                throw new InvalidArgumentException('MANIFEST_INVALID: safe_max_party_size_or_sop_limit cannot be blank.');
            }
            $boats[] = [
                'name' => $name,
                'buffer_before_minutes' => self::boundedInt($row, 'buffer_before_minutes', 0, 1440),
                'buffer_after_minutes' => self::boundedInt($row, 'buffer_after_minutes', 0, 1440),
                'safe_max_party_size_or_sop_limit' => $safeLimit === null ? null : (string) $safeLimit,
            ];
        }
        if ($boats === []) {
            throw new InvalidArgumentException('MANIFEST_INVALID: at least one boat is required.');
        }

        $tripTemplates = [];
        $templateCodes = [];
        foreach (self::requiredList($input, 'trip_templates') as $index => $row) {
            if (! is_array($row) || array_is_list($row)) {
                throw new InvalidArgumentException("MANIFEST_INVALID: trip_templates.{$index} must be an object.");
            }
            $code = self::identity(self::requiredString($row, 'code'), "trip_templates.{$index}.code");
            if (isset($templateCodes[$code])) {
                throw new InvalidArgumentException("MANIFEST_INVALID: duplicate trip template code {$code}.");
            }
            $templateCodes[$code] = true;
            $tripTemplates[] = ['code' => $code, 'name' => self::requiredString($row, 'name')];
        }
        if ($tripTemplates === []) {
            throw new InvalidArgumentException('MANIFEST_INVALID: at least one trip template is required.');
        }

        $slots = [];
        $slotCodes = [];
        foreach (self::requiredList($input, 'slots') as $index => $row) {
            if (! is_array($row) || array_is_list($row)) {
                throw new InvalidArgumentException("MANIFEST_INVALID: slots.{$index} must be an object.");
            }
            $identity = self::identity(self::requiredString($row, 'identity'), "slots.{$index}.identity");
            if (isset($slotCodes[$identity])) {
                throw new InvalidArgumentException("MANIFEST_INVALID: duplicate slot identity {$identity}.");
            }
            $slotCodes[$identity] = true;
            $start = self::time(self::requiredString($row, 'service_start'), "slots.{$index}.service_start");
            $end = self::time(self::requiredString($row, 'service_end'), "slots.{$index}.service_end");
            $duration = self::durationMinutes($start, $end, "slots.{$index}");
            if (array_key_exists('duration_minutes', $row)) {
                $declaredDuration = self::boundedInt($row, 'duration_minutes', 1, 1440);
                if ($declaredDuration !== $duration) {
                    throw new InvalidArgumentException("MANIFEST_INVALID: slots.{$index}.duration_minutes does not match service_start/service_end.");
                }
            }
            $applicableBoats = self::stringList($row, 'applicable_boats', false);
            if ($applicableBoats === []) {
                throw new InvalidArgumentException("MANIFEST_INVALID: slots.{$index}.applicable_boats cannot be empty.");
            }
            if (count($applicableBoats) !== count(array_unique($applicableBoats))) {
                throw new InvalidArgumentException("MANIFEST_INVALID: slots.{$index}.applicable_boats contains duplicates.");
            }
            foreach ($applicableBoats as $boatName) {
                if (! isset($boatNames[$boatName])) {
                    throw new InvalidArgumentException("MANIFEST_INVALID: slot {$identity} references unknown boat {$boatName}.");
                }
            }
            $operatingTimeStatus = strtoupper((string) ($row['operating_time_status'] ?? 'UNVERIFIED'));
            if (! in_array($operatingTimeStatus, ['UNVERIFIED', 'FICTIONAL_VALIDATION_SCENARIO'], true)) {
                throw new InvalidArgumentException('MANIFEST_INVALID: slot operating_time_status must be UNVERIFIED or FICTIONAL_VALIDATION_SCENARIO.');
            }
            $slots[] = [
                'identity' => $identity,
                'name' => self::requiredString($row, 'name'),
                'service_start' => $start,
                'service_end' => $end,
                'duration_minutes' => $duration,
                'additional_buffer_before_minutes' => self::boundedInt($row, 'additional_buffer_before_minutes', 0, 1440, 0),
                'additional_buffer_after_minutes' => self::boundedInt($row, 'additional_buffer_after_minutes', 0, 1440, 0),
                'operating_time_status' => $operatingTimeStatus,
                'applicable_boats' => array_values($applicableBoats),
            ];
        }
        if ($slots === []) {
            throw new InvalidArgumentException('MANIFEST_INVALID: at least one slot is required.');
        }

        $compatibility = [];
        $pairs = [];
        foreach (self::optionalList($input, 'compatibility') as $index => $row) {
            if (! is_array($row) || array_is_list($row)) {
                throw new InvalidArgumentException("MANIFEST_INVALID: compatibility.{$index} must be an object.");
            }
            $first = self::identity(self::requiredString($row, 'slot_a'), "compatibility.{$index}.slot_a");
            $second = self::identity(self::requiredString($row, 'slot_b'), "compatibility.{$index}.slot_b");
            if (! isset($slotCodes[$first]) || ! isset($slotCodes[$second])) {
                throw new InvalidArgumentException('MANIFEST_INVALID: compatibility references an unknown slot.');
            }
            if ($first === $second) {
                throw new InvalidArgumentException('MANIFEST_INVALID: compatibility requires two different slots.');
            }
            $policy = strtoupper(self::requiredString($row, 'policy'));
            if (! in_array($policy, ['ALLOW', 'DENY'], true)) {
                throw new InvalidArgumentException('MANIFEST_INVALID: compatibility policy must be ALLOW or DENY.');
            }
            $canonical = [$first, $second];
            sort($canonical, SORT_STRING);
            $pairKey = implode(':', $canonical);
            if (isset($pairs[$pairKey])) {
                throw new InvalidArgumentException("MANIFEST_INVALID: duplicate compatibility pair {$pairKey}.");
            }
            $pairs[$pairKey] = true;
            $reason = isset($row['reason']) ? trim((string) $row['reason']) : null;
            if ($reason === '') {
                $reason = null;
            }
            if ($reason !== null && mb_strlen($reason) > 500) {
                throw new InvalidArgumentException('MANIFEST_INVALID: compatibility reason exceeds 500 characters.');
            }
            $compatibility[] = [
                'slot_a' => $first,
                'slot_b' => $second,
                'policy' => $policy,
                'reason' => $reason,
            ];
        }

        $operatorInput = self::requiredObject($input, 'operator');
        $membership = strtoupper(self::requiredString($operatorInput, 'organization_membership'));
        if ($membership !== 'ACTIVE') {
            throw new InvalidArgumentException('MANIFEST_INVALID: operator.organization_membership must be ACTIVE.');
        }
        $permissionsInput = self::requiredObject($operatorInput, 'required_permissions');
        $permissions = [];
        foreach (['can_calendar_read', 'can_booking_workflow', 'can_block'] as $permission) {
            if (! array_key_exists($permission, $permissionsInput) || ! is_bool($permissionsInput[$permission])) {
                throw new InvalidArgumentException("MANIFEST_INVALID: operator.required_permissions.{$permission} must be boolean.");
            }
            $permissions[$permission] = $permissionsInput[$permission];
        }
        if (! in_array(true, $permissions, true)) {
            throw new InvalidArgumentException('MANIFEST_INVALID: operator must receive at least one permission.');
        }
        $email = strtolower(self::requiredString($operatorInput, 'email'));
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new InvalidArgumentException('MANIFEST_INVALID: operator.email is invalid.');
        }
        $operator = [
            'name' => self::requiredString($operatorInput, 'name'),
            'email' => $email,
            'organization_membership' => $membership,
            'required_permissions' => $permissions,
        ];

        $serviceBoundaryInput = self::requiredObject($input, 'pilot_service_boundary');
        $included = self::stringList($serviceBoundaryInput, 'included', false);
        $excluded = self::stringList($serviceBoundaryInput, 'excluded', true);
        if ($included === []) {
            throw new InvalidArgumentException('MANIFEST_INVALID: pilot_service_boundary.included cannot be empty.');
        }
        if (array_intersect($included, $excluded) !== []) {
            throw new InvalidArgumentException('MANIFEST_INVALID: service boundary included/excluded entries overlap.');
        }

        $productToSlotSop = [];
        $products = [];
        foreach (self::optionalList($input, 'product_to_slot_sop') as $index => $row) {
            if (! is_array($row) || array_is_list($row)) {
                throw new InvalidArgumentException("MANIFEST_INVALID: product_to_slot_sop.{$index} must be an object.");
            }
            $product = self::requiredString($row, 'product');
            if (isset($products[$product])) {
                throw new InvalidArgumentException("MANIFEST_INVALID: duplicate product SOP entry {$product}.");
            }
            $products[$product] = true;
            $approvedSlots = self::stringList($row, 'approved_slots', false);
            if ($approvedSlots === []) {
                throw new InvalidArgumentException("MANIFEST_INVALID: product_to_slot_sop.{$index}.approved_slots cannot be empty.");
            }
            foreach ($approvedSlots as $slotIdentity) {
                if (! isset($slotCodes[$slotIdentity])) {
                    throw new InvalidArgumentException("MANIFEST_INVALID: product SOP references unknown slot {$slotIdentity}.");
                }
            }
            $productToSlotSop[] = ['product' => $product, 'approved_slots' => array_values(array_unique($approvedSlots))];
        }

        usort($boats, static fn (array $a, array $b): int => $a['name'] <=> $b['name']);
        usort($tripTemplates, static fn (array $a, array $b): int => $a['code'] <=> $b['code']);
        usort($slots, static fn (array $a, array $b): int => $a['identity'] <=> $b['identity']);
        usort($compatibility, static fn (array $a, array $b): int => [$a['slot_a'], $a['slot_b']] <=> [$b['slot_a'], $b['slot_b']]);
        usort($productToSlotSop, static fn (array $a, array $b): int => $a['product'] <=> $b['product']);
        sort($included, SORT_STRING);
        sort($excluded, SORT_STRING);

        return new self([
            'version' => 'v1',
            'organization' => $organization,
            'boats' => $boats,
            'trip_templates' => $tripTemplates,
            'slots' => $slots,
            'compatibility' => $compatibility,
            'hold_ttl_minutes' => self::boundedInt($input, 'hold_ttl_minutes', 1, 1440),
            'operator' => $operator,
            'pilot_service_boundary' => ['included' => $included, 'excluded' => $excluded],
            'product_to_slot_sop' => $productToSlotSop,
        ]);
    }

    /** @return array<string, mixed> */
    public function data(): array
    {
        return $this->data;
    }

    public function sha256(): string
    {
        return hash('sha256', json_encode($this->data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }

    /** @param array<string, mixed> $data */
    private static function assertNoSecrets(array $data, string $path = ''): void
    {
        foreach ($data as $key => $value) {
            $keyString = strtolower((string) $key);
            $currentPath = $path === '' ? (string) $key : $path.'.'.$key;
            if (preg_match('/(^|_)(password|secret|token|app_key|db_credentials|api_credentials)($|_)/', $keyString) === 1) {
                throw new InvalidArgumentException("MANIFEST_INVALID: secret field {$currentPath} is forbidden.");
            }
            if (is_array($value)) {
                self::assertNoSecrets($value, $currentPath);
            }
        }
    }

    /** @param array<string, mixed> $data */
    private static function requiredString(array $data, string $key): string
    {
        if (! array_key_exists($key, $data) || ! is_string($data[$key]) || trim($data[$key]) === '') {
            throw new InvalidArgumentException("MANIFEST_INVALID: {$key} must be a non-empty string.");
        }

        return trim($data[$key]);
    }

    /** @param array<string, mixed> $data @return array<string, mixed> */
    private static function requiredObject(array $data, string $key): array
    {
        if (! array_key_exists($key, $data) || ! is_array($data[$key]) || array_is_list($data[$key])) {
            throw new InvalidArgumentException("MANIFEST_INVALID: {$key} must be an object.");
        }

        return $data[$key];
    }

    /** @param array<string, mixed> $data @return list<mixed> */
    private static function requiredList(array $data, string $key): array
    {
        if (! array_key_exists($key, $data) || ! is_array($data[$key]) || ! array_is_list($data[$key])) {
            throw new InvalidArgumentException("MANIFEST_INVALID: {$key} must be a list.");
        }

        return $data[$key];
    }

    /** @param array<string, mixed> $data @return list<mixed> */
    private static function optionalList(array $data, string $key): array
    {
        if (! array_key_exists($key, $data)) {
            return [];
        }

        return self::requiredList($data, $key);
    }

    /** @param array<string, mixed> $data */
    private static function boundedInt(array $data, string $key, int $min, int $max, ?int $default = null): int
    {
        if (! array_key_exists($key, $data)) {
            if ($default !== null) {
                return $default;
            }
            throw new InvalidArgumentException("MANIFEST_INVALID: {$key} is required.");
        }
        if (! is_int($data[$key]) || $data[$key] < $min || $data[$key] > $max) {
            throw new InvalidArgumentException("MANIFEST_INVALID: {$key} must be an integer between {$min} and {$max}.");
        }

        return $data[$key];
    }

    /** @param array<string, mixed> $data @return list<string> */
    private static function stringList(array $data, string $key, bool $allowEmpty): array
    {
        $list = self::requiredList($data, $key);
        $result = [];
        foreach ($list as $value) {
            if (! is_string($value) || trim($value) === '') {
                throw new InvalidArgumentException("MANIFEST_INVALID: {$key} entries must be non-empty strings.");
            }
            $result[] = trim($value);
        }
        if (! $allowEmpty && $result === []) {
            throw new InvalidArgumentException("MANIFEST_INVALID: {$key} cannot be empty.");
        }

        return $result;
    }

    private static function identity(string $value, string $path): string
    {
        $value = strtoupper($value);
        if (preg_match('/^[A-Z0-9][A-Z0-9_-]{1,99}$/', $value) !== 1) {
            throw new InvalidArgumentException("MANIFEST_INVALID: {$path} is invalid.");
        }

        return $value;
    }

    private static function time(string $value, string $path): string
    {
        foreach (['!H:i:s', '!H:i'] as $format) {
            $date = DateTimeImmutable::createFromFormat($format, $value, new DateTimeZone('UTC'));
            $errors = DateTimeImmutable::getLastErrors();
            if ($date !== false && ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0))) {
                return $date->format('H:i:s');
            }
        }

        throw new InvalidArgumentException("MANIFEST_INVALID: {$path} must be HH:MM or HH:MM:SS.");
    }

    private static function durationMinutes(string $start, string $end, string $path): int
    {
        $startAt = DateTimeImmutable::createFromFormat('!H:i:s', $start, new DateTimeZone('UTC'));
        $endAt = DateTimeImmutable::createFromFormat('!H:i:s', $end, new DateTimeZone('UTC'));
        if ($startAt === false || $endAt === false || $endAt <= $startAt) {
            throw new InvalidArgumentException("MANIFEST_INVALID: {$path} crosses midnight or has a non-positive duration.");
        }
        $seconds = $endAt->getTimestamp() - $startAt->getTimestamp();
        if ($seconds % 60 !== 0) {
            throw new InvalidArgumentException("MANIFEST_INVALID: {$path} duration must resolve to whole minutes.");
        }

        return intdiv($seconds, 60);
    }
}
