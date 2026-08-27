<?php

declare(strict_types=1);

namespace App\Core\Localization;

use RuntimeException;
use Throwable;

/**
 * TranslationManager'ın attığı TEK hata türü — controller seviyesinde
 * yakalanıp kullanıcıya flash-mesaj/JSON hatası olarak gösterilir, asla
 * 500'e düşürülmez (Core Never Dies).
 */
final class TranslationManagerException extends RuntimeException
{
    public function __construct(string $message, ?Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }
}
