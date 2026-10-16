<?php

declare(strict_types=1);

namespace App\Tests\Unit\Core\Money;

use App\Core\Money\Money;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Money::class)]
final class MoneyTest extends TestCase
{
    public function testNormalisesAmountAndCurrency(): void
    {
        $price = Money::of('1250.5', ' try ');

        self::assertSame('1250.50', $price->amount);
        self::assertSame('TRY', $price->currency);
        self::assertSame('1250.50 TRY', (string) $price);
    }

    public function testArithmeticIsExact(): void
    {
        $total = Money::of('0.10', 'EUR')->plus(Money::of('0.20', 'EUR'));

        self::assertSame('0.30', $total->amount);
        self::assertSame('-0.10', Money::of('0.20', 'EUR')->minus(Money::of('0.30', 'EUR'))->amount);
        self::assertTrue(Money::of('5', 'EUR')->minus(Money::of('5', 'EUR'))->isZero());
        self::assertTrue(Money::of('-1', 'EUR')->isNegative());
    }

    public function testCompareAndEquals(): void
    {
        self::assertSame(1, Money::of('2', 'EUR')->compareTo(Money::of('1.99', 'EUR')));
        self::assertTrue(Money::of('2', 'EUR')->equals(Money::of('2.00', 'eur')));
        self::assertFalse(Money::of('2', 'EUR')->equals(Money::of('2', 'USD')));
    }

    public function testRefusesToMixCurrencies(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        Money::of('1', 'EUR')->plus(Money::of('1', 'USD'));
    }

    public function testRefusesAnInvalidCurrencyCode(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        Money::of('1', 'EURO');
    }

    public function testZeroDecimalCurrency(): void
    {
        self::assertSame('1500', Money::of('1499.6', 'JPY', 0)->amount);
    }
}
