<?php

namespace Tests\Unit;

use App\Support\MinorUnitAmount;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class MinorUnitAmountTest extends TestCase
{
    public function test_decimal_input_converts_to_minor_units_without_floating_point_or_rounding(): void
    {
        foreach ([
            '0' => 0,
            '0.0' => 0,
            '000.01' => 1,
            '12.3' => 1230,
            '1234.56' => 123456,
        ] as $decimal => $minor) {
            $this->assertSame($minor, MinorUnitAmount::fromDecimal($decimal));
        }

        $maximum = intdiv(PHP_INT_MAX, 100).'.'.str_pad((string) (PHP_INT_MAX % 100), 2, '0', STR_PAD_LEFT);
        $this->assertSame(PHP_INT_MAX, MinorUnitAmount::fromDecimal($maximum));
    }

    public function test_ambiguous_or_out_of_range_decimal_input_is_rejected_instead_of_rounded(): void
    {
        $overflow = (intdiv(PHP_INT_MAX, 100) + 1).'.00';

        foreach (['', '-1', '1.001', '1e2', '1,00', ' 1.00', '1.00 ', $overflow] as $invalid) {
            try {
                MinorUnitAmount::fromDecimal($invalid);
                $this->fail("Expected [{$invalid}] to be rejected.");
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_minor_units_format_as_fixed_two_decimal_operator_input(): void
    {
        $this->assertSame('0.00', MinorUnitAmount::toDecimal(0));
        $this->assertSame('0.01', MinorUnitAmount::toDecimal(1));
        $this->assertSame('1234.56', MinorUnitAmount::toDecimal(123456));
    }

    public function test_only_configured_two_decimal_currencies_are_accepted(): void
    {
        $this->assertSame(12345, MinorUnitAmount::fromDecimal('123.45', 'USD'));
        $this->assertSame('123.45', MinorUnitAmount::toDecimal(12345, 'USD'));

        foreach (['JPY', 'KWD', 'XYZ'] as $currency) {
            try {
                MinorUnitAmount::fromDecimal('1.00', $currency);
                $this->fail("Expected [{$currency}] to be rejected.");
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }
}
