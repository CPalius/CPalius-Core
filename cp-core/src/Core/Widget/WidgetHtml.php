<?php

declare(strict_types=1);

namespace App\Core\Widget;

/**
 * Small output helpers shared by widget types. Everything a type prints goes through esc() or safeUrl():
 * a stored setting is never trusted to be clean, even though it was sanitised on the way in.
 */
final class WidgetHtml
{
    public static function esc(string $text): string
    {
        return htmlspecialchars($text, \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8');
    }

    /**
     * http(s) addresses and site-relative paths only; anything else (javascript:, data:, //host) is dropped.
     */
    public static function safeUrl(string $url): string
    {
        $url = trim($url);
        if ($url === '' || (str_starts_with($url, '/') && !str_starts_with($url, '//'))) {
            return $url;
        }

        return preg_match('~^https?://[^\s<>"\']+$~i', $url) === 1 ? $url : '';
    }

    /**
     * @param list<string> $itemsHtml already-escaped inner HTML of each <li>
     */
    public static function ul(array $itemsHtml): string
    {
        if ($itemsHtml === []) {
            return '';
        }

        return '<ul>'.implode('', array_map(static fn (string $item): string => '<li>'.$item.'</li>', $itemsHtml)).'</ul>';
    }

    /**
     * @param string $rel extra rel token(s) such as "nofollow"; only letters and spaces are kept
     */
    public static function link(string $url, string $label, bool $newTab = false, string $rel = ''): string
    {
        $url = self::safeUrl($url);
        if ($url === '') {
            return self::esc($label);
        }

        $rel = trim(($newTab ? 'noopener noreferrer ' : '').(string) preg_replace('/[^a-z ]/', '', strtolower($rel)));

        return '<a href="'.self::esc($url).'"'.($newTab ? ' target="_blank"' : '').($rel !== '' ? ' rel="'.self::esc($rel).'"' : '').'>'.self::esc($label).'</a>';
    }
}
