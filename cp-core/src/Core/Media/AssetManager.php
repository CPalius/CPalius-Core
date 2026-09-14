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
        private readonly ?MediaOffloader $offloader = null,
    ) {
    }

    /**
     * @param list<string>|null $allowedMimePrefixes narrower allowlist for this call (e.g. ['image/']); null = core list
     * @param int|null          $maxBytes            reject larger uploads before write
     *
     * @throws InvalidUploadException        upload failed, is too large, or MIME could not be detected from content
     * @throws UnsupportedAssetTypeException detected MIME is not on the core allowlist, or not in $allowedMimePrefixes
     */
    public function upload(UploadedFile $uploadedFile, ?array $allowedMimePrefixes = null, ?int $maxBytes = null): Asset
    {
        // Step 1: reject broken PHP uploads (size limit, partial transfer, missing temp dir).
        if (!$uploadedFile->isValid()) {
            throw new InvalidUploadException(sprintf('Upload failed before validation (PHP error code %d): %s', $uploadedFile->getError(), $uploadedFile->getErrorMessage()));
        }

        $pathname = $uploadedFile->getPathname();

        if (!is_file($pathname) || !is_readable($pathname)) {
            throw new InvalidUploadException(sprintf('Uploaded temporary file is not readable: "%s".', $pathname));
        }

        // Step 2: caller size cap, before the file is read or copied.
        if ($maxBytes !== null) {
            $size = $uploadedFile->getSize();
            $size = $size === false ? (filesize($pathname) ?: 0) : $size;
            if ($size > $maxBytes) {
                throw new InvalidUploadException(sprintf('Upload is %d bytes; the limit here is %d.', $size, $maxBytes));
            }
        }

        // Step 3: detect real MIME from content via finfo (fail-closed; never fall back to extension guessing).
        $detectedMimeType = $this->detectMimeType($pathname);

        // Step 4: core allowlist — fail-closed.
        $extension = $this->mimeTypeAllowlist->extensionFor($detectedMimeType);

        if ($extension === null) {
            throw new UnsupportedAssetTypeException($detectedMimeType);
        }

        // Step 5: caller allowlist, before write — a later check would leave the file on disk.
        if ($allowedMimePrefixes !== null && !$this->matchesPrefix($detectedMimeType, $allowedMimePrefixes)) {
            throw new UnsupportedAssetTypeException($detectedMimeType);
        }

        // Step 6: content hash and dedup lookup.
        $hash = hash_file('sha256', $pathname);

        if ($hash === false) {
            throw new InvalidUploadException(sprintf('Could not compute content hash for uploaded file "%s".', $uploadedFile->getClientOriginalName()));
        }

        $existing = $this->assetRepository->findOneByHash($hash);
        if ($existing !== null) {
            return $existing;
        }

        // Step 7: storage extension comes from validated MIME; client name is display-only.
        $filename = $hash.'.'.$extension;
        $path = date('Y/m');
        $storageKey = $path.'/'.$filename;

        // Step 8: write to disk.
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

        // Step 9: copy to the remote target, when one is configured and verified.
        //
        // After the flush, not before: the asset row is the record of truth and
        // has to exist whether or not a bucket on the other side of the world is
        // reachable this second. offload() swallows its own failures for the
        // same reason — the file is on this disk and the site can serve it, so a
        // failed copy is a missed optimisation, not a failed upload. The nightly
        // sweep collects whatever did not make it.
        $this->offloader?->offload($storageKey);

        return $asset;
    }

    /**
     * @param list<string> $prefixes
     */
    private function matchesPrefix(string $mimeType, array $prefixes): bool
    {
        $mimeType = strtolower($mimeType);

        foreach ($prefixes as $prefix) {
            if ($prefix !== '' && str_starts_with($mimeType, strtolower($prefix))) {
                return true;
            }
        }

        return false;
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
