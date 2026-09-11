<?php

declare(strict_types=1);

namespace App\Core\TextFormat\Filter;

use App\Core\TextFormat\TextFilterContext;
use App\Core\TextFormat\TextFilterInterface;

/**
 * Turns bare http(s) URLs in text nodes into links. Existing tags are left
 * alone (so we never nest `<a>` inside `<a>`). Rel is always forced — Drupal's
 * URL filter does not, which is how `target=_blank` + reverse-tabnabbing leaks
 * through a "safe" format.
 */
final class AutoLinkFilter implements TextFilterInterface
{
    private const URL = '~https?://[^\s<>"\']+~i';

    public function id(): string
    {
        return 'auto_link';
    }

    public function phases(): array
    {
        return [TextFilterContext::PHASE_OUTPUT];
    }

    public function process(string $text, TextFilterContext $context): string
    {
        $parts = preg_split('/(<[^>]+>)/', $text, -1, \PREG_SPLIT_DELIM_CAPTURE);
        if ($parts === false) {
            return $text;
        }

        $insideAnchor = false;
        $out = '';
        foreach ($parts as $part) {
            if (str_starts_with($part, '<')) {
                if (preg_match('/^<\s*a\b/i', $part) === 1) {
                    $insideAnchor = true;
                } elseif (preg_match('/^<\s*\/\s*a\b/i', $part) === 1) {
                    $insideAnchor = false;
                }
                $out .= $part;
                continue;
            }

            if ($insideAnchor || $part === '') {
                $out .= $part;
                continue;
            }

            $out .= preg_replace_callback(self::URL, static function (array $m): string {
                $href = rtrim($m[0], '.,);]');
                $safe = htmlspecialchars($href, \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8');

                return '<a href="'.$safe.'" rel="nofollow noopener noreferrer">'.$safe.'</a>';
            }, $part) ?? $part;
        }

        return $out;
    }
}
