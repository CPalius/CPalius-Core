<?php

declare(strict_types=1);

namespace App\Core\Export;

/**
 * Writes CSV that is safe to open in a spreadsheet.
 *
 * A cell that starts with "=", "+", "-" or "@" is run as a formula by Excel and LibreOffice, so a guest
 * name like =HYPERLINK("http://evil","x") typed into a booking form becomes a live link on whoever exports
 * the list. Such cells are prefixed with an apostrophe (shown as plain text). Real numbers ("-5", "12.50")
 * are left alone, or every negative amount would turn into text.
 */
final class CsvWriter
{
    /** Lets Excel read UTF-8 (Turkish letters) without an import wizard. */
    public const UTF8_BOM = "\xEF\xBB\xBF";

    /**
     * @param resource                        $handle
     * @param array<int, scalar|\Stringable|null> $cells
     */
    public static function writeRow($handle, array $cells, string $delimiter = ','): void
    {
        $row = [];

        foreach ($cells as $cell) {
            $row[] = self::safeCell($cell === null ? '' : (string) $cell);
        }

        // The escape character is passed explicitly (empty): its default is deprecated and not RFC 4180 anyway.
        fputcsv($handle, $row, $delimiter, '"', '', "\r\n");
    }

    public static function safeCell(string $value): string
    {
        $head = ltrim($value);

        if ($head === '' || is_numeric($value)) {
            return $value;
        }

        return str_contains("=+-@\t\r", $head[0]) ? "'".$value : $value;
    }
}
