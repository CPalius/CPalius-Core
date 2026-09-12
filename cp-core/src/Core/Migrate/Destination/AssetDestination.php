<?php

declare(strict_types=1);

namespace App\Core\Migrate\Destination;

use App\Core\Media\AssetManager;
use App\Core\Migrate\MigrationDestinationInterface;
use App\Core\Migrate\MigrationRow;
use App\Entity\Asset;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Brings a file on disk into the asset library.
 *
 * It goes through AssetManager rather than writing an Asset row directly, and
 * that is the whole design. AssetManager is the sole write gateway for media
 * (SEC-01/SEC-02): it detects the real MIME from content with finfo, refuses
 * anything off the allowlist, names the stored file from the validated type
 * rather than the claimed extension, and de-duplicates on a content hash. An
 * importer that inserted rows itself would be a way to write .php into the
 * storage directory by calling a file .jpg — which is exactly the kind of
 * bypass an import of somebody else's uploads folder invites.
 *
 * UploadedFile is constructed in test mode, which is the supported way to feed
 * the upload pipeline a file that did not arrive over HTTP. It changes nothing
 * about validation; it only stops is_uploaded_file() from rejecting a path PHP
 * did not create itself.
 *
 * De-duplication has a consequence worth stating: two source rows with
 * identical bytes map to ONE asset. Rollback therefore deletes the asset that
 * the map records, and a second row pointing at the same asset simply finds it
 * already gone.
 */
final class AssetDestination implements MigrationDestinationInterface
{
    public function __construct(
        private readonly AssetManager $assetManager,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function describe(): string
    {
        return 'Asset library';
    }

    public function entityType(): string
    {
        return 'asset';
    }

    public function write(MigrationRow $row, ?string $existingId): string
    {
        $path = trim($row->getString('path'));

        if ($path === '') {
            throw new \RuntimeException('An asset row needs a "path" pointing at a readable local file.');
        }

        if (!is_file($path) || !is_readable($path)) {
            throw new \RuntimeException(sprintf('Asset file "%s" does not exist or cannot be read.', $path));
        }

        $originalName = trim($row->getString('originalName'));

        $asset = $this->assetManager->upload(new UploadedFile(
            $path,
            $originalName !== '' ? $originalName : basename($path),
            null,
            null,
            true,
        ));

        $this->applyMetadata($asset, $row);

        $id = $asset->getId();

        if ($id === null) {
            throw new \RuntimeException('The asset was stored but has no id; the map cannot record this row.');
        }

        return (string) $id;
    }

    public function delete(string $destinationId): bool
    {
        $asset = $this->entityManager->find(Asset::class, (int) $destinationId);

        if ($asset === null) {
            return false;
        }

        // The stored file is deliberately left alone. Assets de-duplicate on a
        // content hash, so the bytes behind this row may still be the bytes
        // behind an asset somebody uploaded here; deleting the file would break
        // that one too. An orphaned file costs disk, a missing one costs data.
        $this->entityManager->remove($asset);
        $this->entityManager->flush();

        return true;
    }

    /**
     * Alt text and a note of where the file came from, merged rather than
     * replacing what AssetManager already recorded (dimensions, EXIF).
     */
    private function applyMetadata(Asset $asset, MigrationRow $row): void
    {
        $extra = [];

        foreach (['alt', 'caption', 'title', 'sourceUrl'] as $key) {
            $value = trim($row->getString($key));

            if ($value !== '') {
                $extra[$key] = $value;
            }
        }

        if ($extra === []) {
            return;
        }

        $asset->setMetadata([...$asset->getMetadata(), ...$extra]);
        $this->entityManager->flush();
    }
}
