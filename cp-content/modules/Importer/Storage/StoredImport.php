<?php

declare(strict_types=1);

namespace Modules\Importer\Storage;

/**
 * One thing an operator uploaded to import from.
 */
final class StoredImport
{
    public const KIND_FILE = 'file';
    public const KIND_DIRECTORY = 'directory';

    public function __construct(
        public readonly string $id,
        public readonly string $originalName,
        public readonly string $kind,
        public readonly string $path,
        public readonly int $size,
        public readonly \DateTimeImmutable $uploadedAt,
    ) {
    }

    public function isDirectory(): bool
    {
        return $this->kind === self::KIND_DIRECTORY;
    }
}
