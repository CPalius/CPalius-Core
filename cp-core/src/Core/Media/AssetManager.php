<?php

declare(strict_types=1);

namespace App\Core\Media;

use App\Core\Media\Exception\InvalidUploadException;
use App\Core\Media\Exception\UnsupportedAssetTypeException;
use App\Entity\Asset;
use App\Repository\AssetRepository;
use Doctrine\ORM\EntityManagerInterface;
use League\Flysystem\FilesystemOperator;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Persists uploads to cpalius_storage (Flysystem) with hash-based names and returns Asset entities (sole write gateway; SEC-01/SEC-02).
 * Validates finfo MIME, MimeTypeAllowlist, content-hash dedup, and server-side extension mapping before write.
 */
final class AssetManager
{
    public function __construct(
        private readonly FilesystemOperator $cpaliusStorage,
        private readonly EntityManagerInterface $entityManager,
        private readonly AssetRepository $assetRepository,
        private readonly MimeTypeAllowlist $mimeTypeAllowlist,
    ) {
    }

    /**
     * @throws InvalidUploadException        upload failed or MIME could not be detected from content
     * @throws UnsupportedAssetTypeException detected MIME is not on the core allowlist
     */
    public function upload(UploadedFile $uploadedFile): Asset
    {
        // Step 1: reject broken PHP uploads (size limit, partial transfer, missing temp dir).
        if (!$uploadedFile->isValid()) {
            throw new InvalidUploadException(sprintf('Upload failed before validation (PHP error code %d): %s', $uploadedFile->getError(), $uploadedFile->getErrorMessage()));
        }

        $pathname = $uploadedFile->getPathname();

        if (!is_file($pathname) || !is_readable($pathname)) {
            throw new InvalidUploadException(sprintf('Uploaded temporary file is not readable: "%s".', $pathname));
        }

        // Step 2: detect real MIME from content via finfo (fail-closed; never fall back to extension guessing).
        $detectedMimeType = $this->detectMimeType($pathname);

        // Step 3: core allowlist — fail-closed.
        $extension = $this->mimeTypeAllowlist->extensionFor($detectedMimeType);

        if ($extension === null) {
            throw new UnsupportedAssetTypeException($detectedMimeType);
        }

        // Step 4: content hash and dedup lookup.
        $hash = hash_file('sha256', $pathname);

        if ($hash === false) {
            throw new InvalidUploadException(sprintf('Could not compute content hash for uploaded file "%s".', $uploadedFile->getClientOriginalName()));
        }

        $existing = $this->assetRepository->findOneByHash($hash);
        if ($existing !== null) {
            return $existing;
        }

        // Step 5: storage extension comes from validated MIME; client name is display-only.
        $filename = $hash.'.'.$extension;
        $path = date('Y/m');
        $storageKey = $path.'/'.$filename;

        // Step 6: write to disk.
        $stream = fopen($pathname, 'r');
        if ($stream === false) {
            throw new InvalidUploadException(sprintf('Could not open uploaded file for reading: "%s".', $pathname));
        }

        try {
            $this->cpaliusStorage->writeStream($storageKey, $stream);
        } finally {
            if (\is_resource($stream)) {
                fclose($stream);
            }
        }

        $asset = new Asset(
            filename: $filename,
            originalName: $this->sanitizeOriginalName($uploadedFile->getClientOriginalName()),
            path: $path,
            // Persist finfo-detected MIME; never trust client Content-Type headers.
            mimeType: $detectedMimeType,
            fileSize: $uploadedFile->getSize() ?: (filesize($pathname) ?: 0),
            hash: $hash,
        );

        $this->entityManager->persist($asset);
        $this->entityManager->flush();

        return $asset;
    }

    /**
     * Detect MIME type from file content; throws if fileinfo is missing or detection fails (fail-closed).
     */
    private function detectMimeType(string $pathname): string
    {
        if (!class_exists(\finfo::class)) {
            throw new InvalidUploadException('The "fileinfo" PHP extension is required to validate uploads and is not available.');
        }

        $finfo = new \finfo(\FILEINFO_MIME_TYPE);
        $detected = $finfo->file($pathname);

        if ($detected === false || $detected === '') {
            throw new InvalidUploadException('Could not determine the MIME type of the uploaded file.');
        }

        return $detected;
    }

    /**
     * Sanitize client filename for display metadata only (strip path components and control chars; max 255).
     */
    private function sanitizeOriginalName(string $originalName): string
    {
        // Basename only — drop directory components.
        $name = basename(str_replace('\\', '/', $originalName));

        // Strip control characters and NUL bytes.
        $name = preg_replace('/[\x00-\x1F\x7F]/u', '', $name) ?? '';

        $name = trim($name);

        if ($name === '' || $name === '.' || $name === '..') {
            $name = 'dosya';
        }

        return mb_substr($name, 0, 255);
    }
}
