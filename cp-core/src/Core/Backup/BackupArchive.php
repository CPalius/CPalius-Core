<?php

declare(strict_types=1);

namespace App\Core\Backup;

final readonly class BackupArchive
{
    public function __construct(
        public string $filename,
        public string $type,
        public int $sizeBytes,
        public \DateTimeImmutable $createdAt,
    ) {
    }

    public function sizeLabel(): string
    {
        $bytes = $this->sizeBytes;
        if ($bytes < 1024) {
            return $bytes.' B';
        }
        if ($bytes < 1048576) {
            return round($bytes / 1024, 1).' KiB';
        }
        if ($bytes < 1073741824) {
            return round($bytes / 1048576, 2).' MiB';
        }

        return round($bytes / 1073741824, 2).' GiB';
    }

    public function stem(): string
    {
        return BackupFilename::stemFrom($this->filename);
    }
}
