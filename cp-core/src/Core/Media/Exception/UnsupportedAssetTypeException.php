<?php

declare(strict_types=1);

namespace App\Core\Media\Exception;

/**
 * Thrown when finfo-detected MIME is not on MimeTypeAllowlist; $detectedMimeType is content-derived for translation params.
 */
final class UnsupportedAssetTypeException extends AssetUploadException
{
    public function __construct(
        public readonly string $detectedMimeType,
    ) {
        parent::__construct(sprintf(
            'Upload rejected: detected MIME type "%s" is not in the core allowlist.',
            $detectedMimeType,
        ));
    }
}
