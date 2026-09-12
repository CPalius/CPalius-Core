<?php

declare(strict_types=1);

namespace App\Core\Migrate;

/**
 * One value a migration needs before it can run: a file path, a database DSN,
 * the locale to import into.
 *
 * Declared rather than read out of a config array, so `cp:migrate status` can
 * tell an operator what a migration wants before they run it, the import
 * screen can render a form from the same description, and a missing required
 * option fails immediately with its own name instead of surfacing as a null
 * somewhere inside the transform.
 *
 * The KIND is what lets that form be more than a row of text boxes: an option
 * that wants an export file gets a picker listing what has been uploaded, one
 * that wants a database password gets a field that does not render its value
 * back into the page. Without it the screen would have to recognise options by
 * name, and would guess wrong the first time a module named one differently.
 */
final class MigrationOption
{
    /** Free text: a locale, a content type, a table prefix. */
    public const KIND_TEXT = 'text';

    /** A file on the server — an export the operator uploaded or placed. */
    public const KIND_FILE = 'file';

    /** A directory, e.g. a copy of another site's uploads folder. */
    public const KIND_DIRECTORY = 'directory';

    /**
     * A credential. Never rendered back into the page and never written to the
     * import log, because a database password typed into a form has a way of
     * ending up in a screenshot in a support thread.
     */
    public const KIND_SECRET = 'secret';

    public function __construct(
        public readonly string $name,
        public readonly string $description,
        public readonly bool $required = true,
        public readonly ?string $default = null,
        public readonly string $kind = self::KIND_TEXT,
    ) {
        if (trim($name) === '') {
            throw new \InvalidArgumentException('A migration option needs a name.');
        }

        if ($required && $default !== null) {
            throw new \InvalidArgumentException(sprintf('Option "%s" is required, so a default would never be used.', $name));
        }

        if (!\in_array($kind, [self::KIND_TEXT, self::KIND_FILE, self::KIND_DIRECTORY, self::KIND_SECRET], true)) {
            throw new \InvalidArgumentException(sprintf('Unknown option kind "%s" for option "%s".', $kind, $name));
        }
    }

    public static function required(string $name, string $description, string $kind = self::KIND_TEXT): self
    {
        return new self($name, $description, true, null, $kind);
    }

    public static function optional(string $name, string $description, ?string $default = null, string $kind = self::KIND_TEXT): self
    {
        return new self($name, $description, false, $default, $kind);
    }

    /** An export file the operator uploads or points at. */
    public static function file(string $name, string $description): self
    {
        return new self($name, $description, true, null, self::KIND_FILE);
    }

    /** A directory, such as an unpacked uploads folder. */
    public static function directory(string $name, string $description, bool $required = true): self
    {
        return new self($name, $description, $required, null, self::KIND_DIRECTORY);
    }

    public static function secret(string $name, string $description, bool $required = true): self
    {
        return new self($name, $description, $required, null, self::KIND_SECRET);
    }

    public function isSecret(): bool
    {
        return $this->kind === self::KIND_SECRET;
    }
}
