<?php

declare(strict_types=1);

namespace App\Core\Money;

/**
 * An amount of one currency. Immutable, exact (see DecimalString), and it refuses to mix currencies:
 * adding 10 TRY to 10 EUR is a bug the type system should catch, not a quiet 20.
 *
 * The amount is kept as a string at the currency's scale ("1250.50"), which is also what a decimal
 * database column and the Field API's decimal field store, so nothing converts on the way in or out.
 */
final class Money implements \Stringable
{
    private function __construct(
        public readonly string $amount,
        public readonly string $currency,
        public readonly int $scale,
    ) {
    }

    /**
     * @param string $amount   plain decimal ("1250.5"); rounded half away from zero to $scale
     * @param string $currency ISO 4217 code, any case
     * @param int    $scale    decimals kept (2 for most currencies, 0 for JPY, 3 for KWD)
     */
    public static function of(string $amount, string $currency, int $scale = 2): self
    {
        $currency = strtoupper(trim($currency));

        if (preg_match('/^[A-Z]{3}$/', $currency) !== 1) {
            throw new \InvalidArgumentException(sprintf('"%s" is not an ISO 4217 currency code.', $currency));
        }

        if ($scale < 0 || $scale > 6) {
            throw new \InvalidArgumentException('Scale must be between 0 and 6.');
        }

        return new self(DecimalString::round(trim($amount), $scale), $currency, $scale);
    }

    public static function zero(string $currency, int $scale = 2): self
    {
        return self::of('0', $currency, $scale);
    }

    public function plus(self $other): self
    {
        $this->assertComparable($other);

        return new self(DecimalString::add($this->amount, $other->amount, $this->scale), $this->currency, $this->scale);
    }

    public function minus(self $other): self
    {
        $this->assertComparable($other);

        return new self(DecimalString::subtract($this->amount, $other->amount, $this->scale), $this->currency, $this->scale);
    }

    /**
     * @return int -1, 0 or 1
     */
    public function compareTo(self $other): int
    {
        $this->assertComparable($other);

        return DecimalString::compare($this->amount, $other->amount, $this->scale);
    }

    public function equals(self $other): bool
    {
        return $this->currency === $other->currency && $this->scale === $other->scale && $this->amount === $other->amount;
    }

    public function isNegative(): bool
    {
        return $this->amount[0] === '-';
    }

    public function isZero(): bool
    {
        return trim(str_replace(['-', '.'], '', $this->amount), '0') === '';
    }

    public function __toString(): string
    {
        return $this->amount.' '.$this->currency;
    }

    private function assertComparable(self $other): void
    {
        if ($this->currency !== $other->currency || $this->scale !== $other->scale) {
            throw new \InvalidArgumentException(sprintf('Cannot combine %s (scale %d) with %s (scale %d).', $this->currency, $this->scale, $other->currency, $other->scale));
        }
    }
}
