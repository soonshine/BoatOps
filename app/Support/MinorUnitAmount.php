<?php

namespace App\Support;

use Illuminate\Container\Container;
use InvalidArgumentException;

final class MinorUnitAmount
{
    private const SCALE = 100;

    private const EXPONENT = 2;

    private const DEFAULT_CURRENCY = 'THB';

    /** @var array<string, int> */
    private const DEFAULT_CURRENCY_SCALES = [
        'AUD' => 2, 'CAD' => 2, 'CHF' => 2, 'CNY' => 2, 'EUR' => 2,
        'GBP' => 2, 'HKD' => 2, 'SGD' => 2, 'THB' => 2, 'USD' => 2,
    ];

    /** @return list<string> */
    public static function supportedCurrencies(): array
    {
        $configured = self::configuredScales();

        return array_values(array_keys(array_filter($configured, static fn (mixed $scale): bool => is_int($scale) && $scale === self::EXPONENT)));
    }

    public static function defaultCurrency(): string
    {
        return self::DEFAULT_CURRENCY;
    }

    public static function fromDecimal(string $decimal, string $currency = self::DEFAULT_CURRENCY): int
    {
        self::assertSupportedCurrency($currency);

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

    public static function toDecimal(int $minor, string $currency = self::DEFAULT_CURRENCY): string
    {
        self::assertSupportedCurrency($currency);

        if ($minor < 0) {
            throw new InvalidArgumentException('The minor-unit amount cannot be negative.');
        }

        return intdiv($minor, self::SCALE).'.'.str_pad((string) ($minor % self::SCALE), 2, '0', STR_PAD_LEFT);
    }

    private static function assertSupportedCurrency(string $currency): void
    {
        $scales = self::configuredScales();
        if (! array_key_exists($currency, $scales) || $scales[$currency] !== self::EXPONENT) {
            throw new InvalidArgumentException('The currency is not supported by the configured two-decimal minor-unit boundary.');
        }
    }

    /** @return array<string, int> */
    private static function configuredScales(): array
    {
        $configured = Container::getInstance()->bound('config') ? config('currency.minor_unit_scales') : null;

        return is_array($configured) && $configured !== [] ? $configured : self::DEFAULT_CURRENCY_SCALES;
    }
}
