<?php

declare(strict_types=1);

namespace App\Core\Storage;

/**
 * Anything a remote storage target refuses or fails to do.
 *
 * Deliberately one exception type for every driver: callers (the media
 * offloader, the backup shipper) treat a failed FTP login and a rejected S3
 * signature identically — the copy did not happen, say so and carry on with
 * the local file. A per-driver hierarchy would invite catch blocks that handle
 * one transport and let the other escape.
 */
final class StorageException extends \RuntimeException
{
}
