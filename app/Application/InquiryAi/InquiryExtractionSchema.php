<?php

namespace App\Application\InquiryAi;

/**
 * Explicit extraction schema / allowlist for the Issue #51 first AI slice.
 *
 * Only the fields listed below may be accepted from provider output. Provider
 * keys outside the allowlist are discarded deterministically. Values that fail
 * their declared rule normalize to null; the schema never invents a fact and
 * never produces a field that was not present in the allowlist.
 */
final class InquiryExtractionSchema
{
    public const ALLOWED_FIELD_NAMES = [
        'service_date',
        'boat_name',
        'route_summary',
        'contact_name',
        'contact_method',
        'contact_value',
        'party_size',
        'pickup_required',
        'hotel_name',
        'room_number',
        'pickup_time',
        'meeting_point',
        'service_location',
    ];

    /** Mirrors the operator Inquiry form contact method vocabulary. */
    public const CONTACT_METHODS = ['PHONE', 'WHATSAPP', 'WECHAT', 'LINE', 'EMAIL', 'OTHER'];

    private const STRING_MAX_LENGTHS = [
        'boat_name' => 255,
        'route_summary' => 2000,
        'contact_name' => 255,
        'contact_value' => 255,
        'hotel_name' => 255,
        'room_number' => 255,
        'meeting_point' => 2000,
        'service_location' => 2000,
    ];

    private const PARTY_SIZE_MIN = 1;

    private const PARTY_SIZE_MAX = 999;

    /**
     * Normalize one decoded provider JSON object against the allowlist.
     *
     * @param  array<string, mixed>  $providerData
     * @return array<string, mixed> one entry per allowed field; null when unknown/unsupported
     */
    public static function normalize(array $providerData): array
    {
        $result = array_fill_keys(self::ALLOWED_FIELD_NAMES, null);

        foreach (self::ALLOWED_FIELD_NAMES as $key) {
            if (! array_key_exists($key, $providerData)) {
                continue;
            }

            $result[$key] = self::normalizeValue($key, $providerData[$key]);
        }

        return $result;
    }

    private static function normalizeValue(string $key, mixed $value): mixed
    {
        if ($value === null) {
            return null;
        }

        return match ($key) {
            'service_date' => self::normalizeDate($value),
            'pickup_time' => self::normalizeTime($value),
            'party_size' => self::normalizePartySize($value),
            'pickup_required' => is_bool($value) ? $value : null,
            'contact_method' => self::normalizeContactMethod($value),
            default => self::normalizeString($value, self::STRING_MAX_LENGTHS[$key]),
        };
    }

    private static function normalizeDate(mixed $value): ?string
    {
        if (! is_string($value) || ! preg_match('/\A\d{4}-\d{2}-\d{2}\z/', $value)) {
            return null;
        }

        [$year, $month, $day] = array_map('intval', explode('-', $value));

        return checkdate($month, $day, $year) ? $value : null;
    }

    private static function normalizeTime(mixed $value): ?string
    {
        if (! is_string($value) || ! preg_match('/\A(?:[01]\d|2[0-3]):[0-5]\d\z/', $value)) {
            return null;
        }

        return $value;
    }

    private static function normalizePartySize(mixed $value): ?int
    {
        if (is_bool($value)) {
            return null;
        }

        $size = null;
        if (is_int($value)) {
            $size = $value;
        } elseif (is_string($value) && preg_match('/\A\d{1,3}\z/', $value)) {
            $size = (int) $value;
        }

        if ($size === null) {
            return null;
        }

        return $size >= self::PARTY_SIZE_MIN && $size <= self::PARTY_SIZE_MAX ? $size : null;
    }

    private static function normalizeContactMethod(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $candidate = strtoupper(trim($value));

        return in_array($candidate, self::CONTACT_METHODS, true) ? $candidate : null;
    }

    private static function normalizeString(mixed $value, int $maxLength): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        if ($trimmed === '') {
            return null;
        }

        return mb_strlen($trimmed) <= $maxLength ? $trimmed : null;
    }
}
