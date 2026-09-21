<?php

declare(strict_types=1);

namespace App\Core\Font;

/**
 * The typefaces a visitor already has.
 *
 * Offered next to the installed families because choosing Arial should not
 * require downloading Arial: these cost nothing, never fail to load, and are
 * the right answer for a site that wants to look ordinary on purpose. The
 * prefix keeps them from ever colliding with a family slug.
 */
final class FontStack
{
    public const PREFIX = 'system:';

    /**
     * @return array<string, string> value => stack
     */
    public static function all(): array
    {
        return [
            self::PREFIX.'system' => 'system-ui, -apple-system, "Segoe UI", Roboto, Arial, sans-serif',
            self::PREFIX.'arial' => 'Arial, "Helvetica Neue", Helvetica, sans-serif',
            self::PREFIX.'helvetica' => '"Helvetica Neue", Helvetica, Arial, sans-serif',
            self::PREFIX.'tahoma' => 'Tahoma, Verdana, Segoe, sans-serif',
            self::PREFIX.'verdana' => 'Verdana, Geneva, Tahoma, sans-serif',
            self::PREFIX.'trebuchet' => '"Trebuchet MS", "Lucida Grande", Tahoma, sans-serif',
            self::PREFIX.'georgia' => 'Georgia, "Times New Roman", serif',
            self::PREFIX.'times' => '"Times New Roman", Times, serif',
            self::PREFIX.'garamond' => 'Garamond, Baskerville, "Times New Roman", serif',
            self::PREFIX.'courier' => '"Courier New", Courier, monospace',
            self::PREFIX.'consolas' => 'Consolas, "Lucida Console", Monaco, monospace',
            self::PREFIX.'mono' => 'ui-monospace, SFMono-Regular, Menlo, Consolas, monospace',
        ];
    }

    public static function system(string $value): ?string
    {
        return self::all()[$value] ?? null;
    }

    public static function isSystem(string $value): bool
    {
        return str_starts_with($value, self::PREFIX);
    }

    /**
     * Label key for the settings screen, derived so a new entry above needs no
     * second registration.
     */
    public static function labelKey(string $value): string
    {
        return 'aacp.fonts.stack.'.substr($value, \strlen(self::PREFIX));
    }
}
