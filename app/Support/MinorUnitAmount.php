<?php

namespace App\Support;

use InvalidArgumentException;

final class MinorUnitAmount
{
    private const SCALE = 100;

    public static function fromDecimal(string $decimal): int
    {
        if (preg_match('/\A(\d+)(?:\.(\d{1,2}))?\z/', $decimal, $matches) !== 1) {
            throw new InvalidArgumentException('The amount must be a non-negative decimal with no more than two decimal places.');
        }

        $whole = ltrim($matches[1], '0');
        $whole = $whole === '' ? '0' : $whole;
        $fraction = str_pad($matches[2] ?? '', 2, '0');
        $maxWhole = (string) intdiv(PHP_INT_MAX, self::SCALE);
        $wholeExceedsMaximum = strlen($whole) > strlen($maxWhole)
            || (strlen($whole) === strlen($maxWhole) && strcmp($whole, $maxWhole) > 0);

        if ($wholeExceedsMaximum
            || ($whole === $maxWhole && (int) $fraction > PHP_INT_MAX % self::SCALE)) {
            throw new InvalidArgumentException('The amount exceeds the supported minor-unit integer range.');
        }

        return ((int) $whole * self::SCALE) + (int) $fraction;
    }

    public static function toDecimal(int $minor): string
    {
        if ($minor < 0) {
            throw new InvalidArgumentException('The minor-unit amount cannot be negative.');
        }

        return intdiv($minor, self::SCALE).'.'.str_pad((string) ($minor % self::SCALE), 2, '0', STR_PAD_LEFT);
    }
}
