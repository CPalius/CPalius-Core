<?php

declare(strict_types=1);

namespace App\Core\Cron;

/**
 * Pure-PHP matcher for a 5-field cron expression against a DateTimeImmutable (no extra package).
 * Invalid or unsupported syntax (e.g. @daily) always returns false; no next-run calculation.
 */
final class CronExpressionEvaluator
{
    public function isDue(string $expression, \DateTimeImmutable $at): bool
    {
        $fields = preg_split('/\s+/', trim($expression));
        if ($fields === false || \count($fields) !== 5) {
            return false;
        }

        [$minute, $hour, $dayOfMonth, $month, $dayOfWeek] = $fields;

        return $this->fieldMatches($minute, (int) $at->format('i'), 0, 59)
            && $this->fieldMatches($hour, (int) $at->format('G'), 0, 23)
            && $this->fieldMatches($dayOfMonth, (int) $at->format('j'), 1, 31)
            && $this->fieldMatches($month, (int) $at->format('n'), 1, 12)
            && $this->fieldMatches($dayOfWeek, (int) $at->format('w'), 0, 6);
    }

    /**
     * Syntax-only check of a 5-field expression for AACP form validation (not date matching).
     */
    public function isValidExpression(string $expression): bool
    {
        $fields = preg_split('/\s+/', trim($expression));
        if ($fields === false || \count($fields) !== 5) {
            return false;
        }

        $ranges = [[0, 59], [0, 23], [1, 31], [1, 12], [0, 6]];

        foreach ($fields as $index => $field) {
            [$min, $max] = $ranges[$index];
            if (!$this->isFieldSyntaxValid($field, $min, $max)) {
                return false;
            }
        }

        return true;
    }

    private function fieldMatches(string $field, int $actual, int $min, int $max): bool
    {
        foreach (explode(',', $field) as $part) {
            if ($this->partMatches($part, $actual, $min, $max)) {
                return true;
            }
        }

        return false;
    }

    private function partMatches(string $part, int $actual, int $min, int $max): bool
    {
        $step = 1;
        if (str_contains($part, '/')) {
            [$part, $stepRaw] = explode('/', $part, 2);
            if (!ctype_digit($stepRaw) || (int) $stepRaw < 1) {
                return false;
            }
            $step = (int) $stepRaw;
        }

        if ($part === '*') {
            $rangeMin = $min;
            $rangeMax = $max;
        } elseif (str_contains($part, '-')) {
            [$rangeMinRaw, $rangeMaxRaw] = explode('-', $part, 2);
            if (!ctype_digit($rangeMinRaw) || !ctype_digit($rangeMaxRaw)) {
                return false;
            }
            $rangeMin = (int) $rangeMinRaw;
            $rangeMax = (int) $rangeMaxRaw;
        } elseif (ctype_digit($part)) {
            $rangeMin = $rangeMax = (int) $part;
        } else {
            return false;
        }

        if ($actual < $rangeMin || $actual > $rangeMax) {
            return false;
        }

        return ($actual - $rangeMin) % $step === 0;
    }

    private function isFieldSyntaxValid(string $field, int $min, int $max): bool
    {
        foreach (explode(',', $field) as $part) {
            if (!$this->isPartSyntaxValid($part, $min, $max)) {
                return false;
            }
        }

        return true;
    }

    private function isPartSyntaxValid(string $part, int $min, int $max): bool
    {
        if (str_contains($part, '/')) {
            [$part, $stepRaw] = explode('/', $part, 2);
            if (!ctype_digit($stepRaw) || (int) $stepRaw < 1) {
                return false;
            }
        }

        if ($part === '*') {
            return true;
        }

        if (str_contains($part, '-')) {
            [$rangeMinRaw, $rangeMaxRaw] = explode('-', $part, 2);
            if (!ctype_digit($rangeMinRaw) || !ctype_digit($rangeMaxRaw)) {
                return false;
            }

            return (int) $rangeMinRaw >= $min && (int) $rangeMaxRaw <= $max && (int) $rangeMinRaw <= (int) $rangeMaxRaw;
        }

        if (!ctype_digit($part)) {
            return false;
        }

        return (int) $part >= $min && (int) $part <= $max;
    }
}
