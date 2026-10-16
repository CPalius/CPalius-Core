<?php

declare(strict_types=1);

namespace App\Tests\Unit\Core\Money;

use App\Core\Money\DecimalString;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(DecimalString::class)]
final class DecimalStringTest extends TestCase
{
    /**
     * @return iterable<string, array{string, int, string}>
     */
    public static function roundings(): iterable
    {
        yield 'pads the fraction' => ['12', 2, '12.00'];
        yield 'keeps exact' => ['12.34', 2, '12.34'];
        yield 'half rounds up' => ['1.005', 2, '1.01'];
        yield 'below half rounds down' => ['1.004', 2, '1.00'];
        yield 'negative half rounds away from zero' => ['-1.005', 2, '-1.01'];
        yield 'carries into the integer part' => ['9.999', 2, '10.00'];
        yield 'carries through nines' => ['99.995', 2, '100.00'];
        yield 'zero scale' => ['2.5', 0, '3'];
        yield 'leading dot' => ['.5', 2, '0.50'];
        yield 'trailing dot' => ['7.', 2, '7.00'];
        yield 'leading zeros' => ['0007.1', 1, '7.1'];
        yield 'plus sign' => ['+3.2', 1, '3.2'];
        yield 'negative zero collapses' => ['-0.001', 2, '0.00'];
        yield 'beyond float precision' => ['123456789012345678.99', 2, '123456789012345678.99'];
    }

    #[DataProvider('roundings')]
    public function testRound(string $input, int $scale, string $expected): void
    {
        self::assertSame($expected, DecimalString::round($input, $scale));
    }

    #[DataProvider('invalidInputs')]
    public function testRoundRefusesNonDecimals(string $input): void
    {
        $this->expectException(\InvalidArgumentException::class);

        DecimalString::round($input, 2);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidInputs(): iterable
    {
        yield 'empty' => [''];
        yield 'text' => ['abc'];
        yield 'exponent' => ['1e3'];
        yield 'two dots' => ['1.2.3'];
        yield 'sign only' => ['-'];
    }

    public function testAddAndSubtractAreExact(): void
    {
        self::assertSame('0.30', DecimalString::add('0.10', '0.20', 2));
        self::assertSame('0.10', DecimalString::subtract('0.30', '0.20', 2));
        self::assertSame('-0.10', DecimalString::subtract('0.20', '0.30', 2));
        self::assertSame('0.00', DecimalString::subtract('1.25', '1.25', 2));
        self::assertSame('1000.00', DecimalString::add('999.99', '0.01', 2));
        self::assertSame('-5.00', DecimalString::add('-10.00', '5.00', 2));
        self::assertSame('-15.00', DecimalString::add('-10.00', '-5.00', 2));
        self::assertSame('100000000000000000000.01', DecimalString::add('99999999999999999999.99', '0.02', 2));
    }

    public function testCompare(): void
    {
        self::assertSame(0, DecimalString::compare('1.0', '1.00', 2));
        self::assertSame(1, DecimalString::compare('10.00', '9.99', 2));
        self::assertSame(-1, DecimalString::compare('-10.00', '-9.99', 2));
        self::assertSame(-1, DecimalString::compare('-0.01', '0.01', 2));
        self::assertSame(1, DecimalString::compare('100', '99', 2));
    }
}
