<?php

declare(strict_types=1);

namespace App\Core\Font;

/**
 * An install that could not finish. The message is a translation key, not prose:
 * the operator reads this on the fonts screen in their own language.
 */
final class FontInstallException extends \RuntimeException
{
}
