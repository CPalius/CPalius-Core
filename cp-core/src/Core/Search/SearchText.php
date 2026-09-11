<?php

declare(strict_types=1);

namespace App\Core\Search;

/**
 * Plain-text snippet for result cards. Strips markup; does not run SQL.
 */
final class SearchText
{
    public static function snippet(?string $value, int $max = 180): ?string
    {
        if ($value === null) {
            return null;
        }

        $text = trim((string) preg_replace('/\s+/u', ' ', strip_tags($value)));
        if ($text === '') {
            return null;
        }

        if (mb_strlen($text) <= $max) {
            return $text;
        }

        return rtrim(mb_substr($text, 0, $max - 1)).'…';
    }
}
