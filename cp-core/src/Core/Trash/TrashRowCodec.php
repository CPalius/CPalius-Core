<?php

declare(strict_types=1);

namespace App\Core\Trash;

/**
 * Makes a raw table row safe for a JSON column and back.
 *
 * Only strings that are not valid UTF-8 (BLOBs, packed IPs) need wrapping;
 * everything else JSON stores as-is. Values stay exactly what the driver
 * returned — restore hands them straight back to the same table.
 */
final class TrashRowCodec
{
    /**
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    public static function encode(array $row): array
    {
        foreach ($row as $column => $value) {
            if (\is_string($value) && !mb_check_encoding($value, 'UTF-8')) {
                $row[$column] = ['__b64' => base64_encode($value)];
            } elseif (\is_resource($value)) {
                $row[$column] = ['__b64' => base64_encode((string) stream_get_contents($value))];
            }
        }

        return $row;
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    public static function decode(array $row): array
    {
        foreach ($row as $column => $value) {
            if (\is_array($value) && isset($value['__b64']) && \count($value) === 1) {
                $row[$column] = base64_decode((string) $value['__b64'], true) ?: '';
            }
        }

        return $row;
    }
}
