<?php

declare(strict_types=1);

namespace App\Core\Localization;

use RuntimeException;
use Throwable;

/**
 * Only exception type from TranslationManager. Controllers turn it into flash/JSON — never HTTP 500.
 */
final class TranslationManagerException extends RuntimeException
{
    public function __construct(string $message, ?Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }
}
