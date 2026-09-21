<?php

declare(strict_types=1);

namespace App\Core\Font;

/**
 * The places a site can choose a typeface for.
 *
 * Kept to four broad roles plus three heading overrides rather than one entry
 * per element. A screen with forty dropdowns is a screen nobody finishes, and
 * the theme only ever asks for four families — everything else inherits.
 *
 * Each role maps to a CSS custom property the theme already reads (see the
 * --cp-font-* block in the theme stylesheet), which is why assigning a font
 * needs no !important and no knowledge of the theme's selectors.
 */
final class FontRole
{
    public const BODY = 'body';
    public const HEADING = 'heading';
    public const MONO = 'mono';
    public const SERIF = 'serif';
    public const H1 = 'h1';
    public const H2 = 'h2';
    public const H3 = 'h3';

    /**
     * Roles that redefine a theme variable. Everything reading that variable
     * follows, which is most of the stylesheet.
     *
     * @return array<string, string> role => CSS custom property
     */
    public static function variableRoles(): array
    {
        return [
            self::BODY => '--cp-font-body',
            self::HEADING => '--cp-font-heading',
            self::MONO => '--cp-font-mono',
            self::SERIF => '--cp-font-serif',
        ];
    }

    /**
     * Roles that need a selector of their own, because the theme has no
     * variable for "h1 specifically". Left empty, they inherit the heading role.
     *
     * @return array<string, string> role => selector
     */
    public static function selectorRoles(): array
    {
        return [
            self::H1 => 'h1, .section-title, .hero-title',
            self::H2 => 'h2',
            self::H3 => 'h3',
        ];
    }

    /**
     * @return list<string>
     */
    public static function all(): array
    {
        return array_merge(array_keys(self::variableRoles()), array_keys(self::selectorRoles()));
    }

    public static function settingKey(string $role): string
    {
        return 'theme.font_'.$role;
    }

    /**
     * Fallback appended after a chosen family, so a font that fails to load
     * lands on something with the right proportions rather than on Times.
     */
    public static function fallbackFor(string $role): string
    {
        return match ($role) {
            self::MONO => 'ui-monospace, SFMono-Regular, Menlo, Consolas, monospace',
            self::SERIF => 'Georgia, "Times New Roman", serif',
            default => '"Segoe UI", Arial, sans-serif',
        };
    }
}
