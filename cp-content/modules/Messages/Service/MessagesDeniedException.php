<?php

declare(strict_types=1);

namespace Modules\Messages\Service;

/**
 * Thrown when a write is refused. The translation key is the flash; never the
 * exception message shown to a visitor.
 */
final class MessagesDeniedException extends \RuntimeException
{
    /**
     * @param array<string, scalar> $parameters
     */
    public function __construct(
        public readonly string $translationKey,
        public readonly array $parameters = [],
    ) {
        parent::__construct($translationKey);
    }
}
