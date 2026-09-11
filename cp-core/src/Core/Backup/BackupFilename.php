<?php

declare(strict_types=1);

namespace App\Core\Backup;

/**
 * Canonical archive names written under cp-core/var/backups/. Never accept a user-supplied path.
 */
final class BackupFilename
{
    public const TYPE_DB = 'db';
    public const TYPE_FILES = 'files';
    public const TYPE_FULL = 'full';

    /** @var list<string> */
    public const TYPES = [self::TYPE_DB, self::TYPE_FILES, self::TYPE_FULL];

    private const PATTERN = '/^cpalius-(db|files|full)-(\d{8})-(\d{6})\.(sql\.gz|zip)$/';

    private const STEM_PATTERN = '/^cpalius-(db|files|full)-\d{8}-\d{6}$/';

    public static function isValid(string $filename): bool
    {
        if ($filename === '' || $filename !== basename($filename) || str_contains($filename, '..')) {
            return false;
        }

        if (preg_match(self::PATTERN, $filename, $matches) !== 1) {
            return false;
        }

        $type = $matches[1];
        $extension = $matches[4];

        if ($type === self::TYPE_DB) {
            return $extension === 'sql.gz';
        }

        return $extension === 'zip';
    }

    public static function typeFrom(string $filename): ?string
    {
        if (!self::isValid($filename)) {
            return null;
        }

        preg_match(self::PATTERN, $filename, $matches);

        return $matches[1] ?? null;
    }

    public static function create(string $type, \DateTimeImmutable $at): string
    {
        if (!\in_array($type, self::TYPES, true)) {
            throw new BackupException(sprintf('Unknown backup type "%s".', $type));
        }

        $stamp = $at->format('Ymd-His');

        if ($type === self::TYPE_DB) {
            return sprintf('cpalius-db-%s.sql.gz', $stamp);
        }

        return sprintf('cpalius-%s-%s.zip', $type, $stamp);
    }

    /**
     * Public download URLs use this stem so nginx does not treat the request as a static .gz/.zip file.
     */
    public static function stemFrom(string $filename): string
    {
        if (!self::isValid($filename)) {
            throw new BackupException('That archive name is not allowed.');
        }

        return preg_replace('/\.(sql\.gz|zip)$/', '', $filename) ?? $filename;
    }

    public static function fromStem(string $stem): string
    {
        if ($stem === '' || $stem !== basename($stem) || str_contains($stem, '..')) {
            throw new BackupException('That archive name is not allowed.');
        }

        if (preg_match(self::STEM_PATTERN, $stem, $matches) !== 1) {
            throw new BackupException('That archive name is not allowed.');
        }

        return $matches[1] === self::TYPE_DB ? $stem.'.sql.gz' : $stem.'.zip';
    }
}
