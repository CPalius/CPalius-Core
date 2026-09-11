<?php

declare(strict_types=1);

namespace App\Core\Content\Twig;

use Twig\Extension\RuntimeExtensionInterface;

/**
 * Data source for {{ html|reading_time }}. Same lazy RuntimeExtensionInterface pattern as SchemaOrgRuntime.
 * Avoids str_word_count() (not locale-aware); uses Unicode whitespace split (/u) for accurate word counts.
 */
final class ReadingTimeRuntime implements RuntimeExtensionInterface
{
    private const WORDS_PER_MINUTE = 200;

    public function calculate(?string $html): int
    {
        if ($html === null || trim($html) === '') {
            return 0;
        }

        $plainText = strip_tags($html);

        $words = preg_split('/\s+/u', trim($plainText), -1, PREG_SPLIT_NO_EMPTY);
        $wordCount = is_array($words) ? count($words) : 0;

        if ($wordCount === 0) {
            return 0;
        }

        return (int) ceil($wordCount / self::WORDS_PER_MINUTE);
    }
}
