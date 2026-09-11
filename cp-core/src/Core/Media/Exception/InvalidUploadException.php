<?php

declare(strict_types=1);

namespace App\Core\Media\Exception;

/**
 * Upload failed before or during validation (PHP upload error, unreadable temp file, or finfo could not detect MIME).
 * Distinct from UnsupportedAssetTypeException; fail-closed when MIME cannot be determined.
 */
final class InvalidUploadException extends AssetUploadException
{
}
