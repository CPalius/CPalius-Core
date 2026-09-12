<?php

declare(strict_types=1);

namespace App\Core\Migrate;

/**
 * One value a migration needs before it can run: a file path, a database DSN,
 * the locale to import into.
 *
 * Declared rather than read out of a config array, so `cp:migrate status` can
 * tell an operator what a migration wants before they run it, the AACP wizard
 * can render a form from the same description, and a missing required option
 * fails immediately with its own name instead of surfacing as a null somewhere
 * inside the transform.
 */
final class MigrationOption
{
    public function __construct(
        public readonly string $name,
        public readonly string $description,
        public readonly bool $required = true,
        public readonly ?string $default = null,
    ) {
        if (trim($name) === '') {
            throw new \InvalidArgumentException('A migration option needs a name.');
        }

        if ($required && $default !== null) {
            throw new \InvalidArgumentException(sprintf('Option "%s" is required, so a default would never be used.', $name));
        }
    }

    public static function required(string $name, string $description): self
    {
        return new self($name, $description);
    }

    public static function optional(string $name, string $description, ?string $default = null): self
    {
        return new self($name, $description, false, $default);
    }
}
