<?php

declare(strict_types=1);

namespace App\Core\Money;

/**
 * Exact decimal arithmetic on strings, so money never passes through a float.
 *
 * Plain PHP on purpose: ext-bcmath is not guaranteed on every host CPalius runs on, and these are the
 * few operations money needs (normalise, round, add, subtract, compare). Rounding is half away from
 * zero, the same rule number_format() and round() use, so values that used to go through a float come
 * out the same, only without the drift.
 */
final class DecimalString
{
    /** A plain decimal literal: optional sign, digits, optional fraction. No exponent, no spaces. */
    public static function isPlain(string $value): bool
    {
        return preg_match('/^[+-]?(?:\d+(?:\.\d*)?|\.\d+)$/', $value) === 1;
    }

    /**
     * @throws \InvalidArgumentException when $value is not a plain decimal
     */
    public static function round(string $value, int $scale): string
    {
        [$negative, $integer, $fraction] = self::parse($value);
        $scale = max(0, $scale);

        $digits = $integer.str_pad(substr($fraction, 0, $scale), $scale, '0');

        if (isset($fraction[$scale]) && $fraction[$scale] >= '5') {
            $digits = self::increment($digits);
        }

        return self::format($negative, $digits, $scale);
    }

    public static function add(string $a, string $b, int $scale): string
    {
        return self::combine($a, $b, $scale, false);
    }

    public static function subtract(string $a, string $b, int $scale): string
    {
        return self::combine($a, $b, $scale, true);
    }

    /**
     * @return int -1, 0 or 1
     */
    public static function compare(string $a, string $b, int $scale): int
    {
        [$negativeA, $digitsA] = self::scaled($a, $scale);
        [$negativeB, $digitsB] = self::scaled($b, $scale);

        if ($negativeA !== $negativeB) {
            return $negativeA ? -1 : 1;
        }

        $order = self::compareMagnitude($digitsA, $digitsB);

        return $negativeA ? -$order : $order;
    }

    private static function combine(string $a, string $b, int $scale, bool $subtract): string
    {
        [$negativeA, $digitsA] = self::scaled($a, $scale);
        [$negativeB, $digitsB] = self::scaled($b, $scale);

        if ($subtract) {
            $negativeB = !$negativeB;
        }

        if ($negativeA === $negativeB) {
            return self::format($negativeA, self::addMagnitude($digitsA, $digitsB), $scale);
        }

        $order = self::compareMagnitude($digitsA, $digitsB);

        if ($order === 0) {
            return self::format(false, '0', $scale);
        }

        return $order > 0
            ? self::format($negativeA, self::subtractMagnitude($digitsA, $digitsB), $scale)
            : self::format($negativeB, self::subtractMagnitude($digitsB, $digitsA), $scale);
    }

    /**
     * @return array{0: bool, 1: string, 2: string} negative, integer digits (no leading zeros), fraction digits
     */
    private static function parse(string $value): array
    {
        if (!self::isPlain($value)) {
            throw new \InvalidArgumentException(sprintf('"%s" is not a plain decimal number.', $value));
        }

        $negative = $value[0] === '-';
        $parts = explode('.', ltrim($value, '+-'), 2);
        $integer = ltrim($parts[0], '0');

        return [$negative, $integer === '' ? '0' : $integer, $parts[1] ?? ''];
    }

    /**
     * The value as a sign and the digits of value * 10^scale, rounded first.
     *
     * @return array{0: bool, 1: string}
     */
    private static function scaled(string $value, int $scale): array
    {
        $rounded = self::round($value, $scale);
        $negative = $rounded[0] === '-';
        $digits = ltrim(str_replace(['-', '.'], '', $rounded), '0');

        return [$negative, $digits === '' ? '0' : $digits];
    }

    private static function format(bool $negative, string $digits, int $scale): string
    {
        $digits = ltrim($digits, '0');
        $digits = str_pad($digits, $scale + 1, '0', \STR_PAD_LEFT);
        $text = $scale > 0 ? substr($digits, 0, -$scale).'.'.substr($digits, -$scale) : $digits;

        // "-0.00" is just "0.00".
        return $negative && trim($digits, '0') !== '' ? '-'.$text : $text;
    }

    private static function increment(string $digits): string
    {
        for ($i = \strlen($digits) - 1; $i >= 0; --$i) {
            if ($digits[$i] !== '9') {
                $digits[$i] = \chr(\ord($digits[$i]) + 1);

                return $digits;
            }

            $digits[$i] = '0';
        }

        return '1'.$digits;
    }

    private static function compareMagnitude(string $a, string $b): int
    {
        return \strlen($a) <=> \strlen($b) ?: strcmp($a, $b) <=> 0;
    }

    private static function addMagnitude(string $a, string $b): string
    {
        $length = max(\strlen($a), \strlen($b));
        $a = str_pad($a, $length, '0', \STR_PAD_LEFT);
        $b = str_pad($b, $length, '0', \STR_PAD_LEFT);
        $carry = 0;
        $out = '';

        for ($i = $length - 1; $i >= 0; --$i) {
            $sum = (int) $a[$i] + (int) $b[$i] + $carry;
            $out = ($sum % 10).$out;
            $carry = intdiv($sum, 10);
        }

        return $carry > 0 ? $carry.$out : $out;
    }

    /** Requires $a >= $b in magnitude. */
    private static function subtractMagnitude(string $a, string $b): string
    {
        $b = str_pad($b, \strlen($a), '0', \STR_PAD_LEFT);
        $borrow = 0;
        $out = '';

        for ($i = \strlen($a) - 1; $i >= 0; --$i) {
            $difference = (int) $a[$i] - (int) $b[$i] - $borrow;
            $borrow = $difference < 0 ? 1 : 0;
            $out = ($difference + $borrow * 10).$out;
        }

        return $out;
    }
}
