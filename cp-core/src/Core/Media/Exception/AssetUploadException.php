<?php

declare(strict_types=1);

namespace App\Core\Media\Exception;

/**
 * Base class for AssetManager upload failures (SEC-02); lets callers distinguish client rejects (400) from server errors (500).
 */
class AssetUploadException extends \RuntimeException
{
}
