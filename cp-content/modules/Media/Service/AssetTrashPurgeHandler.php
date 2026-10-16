<?php

declare(strict_types=1);

namespace Modules\Media\Service;

use App\Core\Media\ImageProcessor;
use App\Core\Media\MediaOffloader;
use App\Core\Trash\Entity\TrashItem;
use App\Core\Trash\TrashPurgeHandlerInterface;
use App\Entity\Asset;
use Doctrine\DBAL\Connection;
use League\Flysystem\FilesystemOperator;

/**
 * Deleting an asset only removes its row (the recycle bin keeps a copy); the
 * file stays on disk so Restore brings back something that still works. The
 * file goes here, once the bin lets go of the row for good.
 *
 * Storage is content-addressed (filename = hash), so one file can back several
 * rows — a "copy", or the same image uploaded again after the first was
 * deleted. The file is kept while any asset row or any other bin item still
 * points at it.
 */
final class AssetTrashPurgeHandler implements TrashPurgeHandlerInterface
{
    public function __construct(
        private readonly Connection $connection,
        private readonly FilesystemOperator $cpaliusStorage,
        private readonly ImageProcessor $imageProcessor,
        private readonly ?MediaOffloader $offloader = null,
    ) {
    }

    public function supports(TrashItem $item): bool
    {
        return $item->getEntityClass() === Asset::class;
    }

    public function onPurge(TrashItem $item): void
    {
        $row = $item->getRowData();
        $filename = (string) ($row['filename'] ?? '');
        $path = (string) ($row['path'] ?? '');

        if ($filename === '' || str_contains($filename, '/') || str_contains($filename, '..') || str_contains($path, '..')) {
            return;
        }

        if ($this->stillReferenced($filename, $path)) {
            return;
        }

        $key = rtrim($path, '/').'/'.$filename;

        if ($this->cpaliusStorage->fileExists($key)) {
            $this->cpaliusStorage->delete($key);
        }

        $this->imageProcessor->purge($key);
        // Best-effort by design: an orphaned remote object costs pennies, and a
        // bucket outage must not stop the bin from emptying.
        $this->offloader?->forget($key);
    }

    private function stillReferenced(string $filename, string $path): bool
    {
        if ((int) $this->connection->fetchOne('SELECT COUNT(*) FROM cp_assets WHERE filename = ? AND path = ?', [$filename, $path]) > 0) {
            return true;
        }

        return (int) $this->connection->fetchOne(
            "SELECT COUNT(*) FROM cp_trash_items WHERE entity_class = ? AND JSON_UNQUOTE(JSON_EXTRACT(row_data, '$.filename')) = ? AND JSON_UNQUOTE(JSON_EXTRACT(row_data, '$.path')) = ?",
            [Asset::class, $filename, $path],
        ) > 0;
    }
}
