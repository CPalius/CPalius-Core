<?php

declare(strict_types=1);

namespace App\Core\Extension;

/**
 * "Does this installed version fall in the range a security advisory names?"
 *
 * Ranges look like "<1.2.0", ">=1.0.0 <1.2.3", "=1.4.0" or "<1.0.0 || >=2.0.0 <2.0.5". Anything that does not
 * parse matches nothing but is reported through valid(), so a typo in an advisory is caught when it is
 * published instead of silently hiding a vulnerable version.
 */
final class AdvisoryMatcher
{
    private const COMPARATOR = '/^(<=|>=|<|>|=)?\s*(\d+(?:\.\d+){0,3}(?:[-.]?(?:alpha|beta|rc|dev)\.?\d*)?)$/i';

    public static function affects(string $version, string $range): bool
    {
        $range = trim($range);
        if ($range === '*') {
            return true;
        }

        foreach (explode('||', $range) as $alternative) {
            $parts = preg_split('/[\s,]+/', trim($alternative), -1, \PREG_SPLIT_NO_EMPTY) ?: [];
            if ($parts === []) {
                continue;
            }

            $all = true;
            foreach (self::joinOperators($parts) as $comparator) {
                if (preg_match(self::COMPARATOR, $comparator, $m) !== 1 || !version_compare($version, $m[2], self::operator($m[1]))) {
                    $all = false;

                    break;
                }
            }
            if ($all) {
                return true;
            }
        }

        return false;
    }

    public static function valid(string $range): bool
    {
        $range = trim($range);
        if ($range === '*') {
            return true;
        }

        foreach (explode('||', $range) as $alternative) {
            $parts = preg_split('/[\s,]+/', trim($alternative), -1, \PREG_SPLIT_NO_EMPTY) ?: [];
            if ($parts === []) {
                return false;
            }
            foreach (self::joinOperators($parts) as $comparator) {
                if (preg_match(self::COMPARATOR, $comparator) !== 1) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * Lets "< 1.2.0" (operator and version apart) mean the same as "<1.2.0".
     *
     * @param list<string> $parts
     *
     * @return list<string>
     */
    private static function joinOperators(array $parts): array
    {
        $out = [];
        for ($i = 0, $n = \count($parts); $i < $n; ++$i) {
            if (preg_match('/^(<=|>=|<|>|=)$/', $parts[$i]) === 1 && isset($parts[$i + 1])) {
                $out[] = $parts[$i].$parts[++$i];

                continue;
            }
            $out[] = $parts[$i];
        }

        return $out;
    }

    private static function operator(string $op): string
    {
        return $op === '' ? '==' : ($op === '=' ? '==' : $op);
    }
}
