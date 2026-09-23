<?php

declare(strict_types=1);

namespace Modules\Importer\Storage;

/**
 * Remembers the source fields for a system so the operator does not retype
 * a database password on every dry run.
 *
 * Lives next to uploaded exports, under var/, for the same reason: a XenForo
 * password is not something the web root should ever serve. The file is not
 * a settings row because settings are backed up and copied between sites;
 * a foreign database credential is local and disposable.
 */
final class ImportSourceStore
{
    public function __construct(
        private readonly string $directory,
    ) {
    }

    /**
     * @return array<string, string>
     */
    public function load(string $system): array
    {
        $path = $this->path($system);

        if (!is_file($path)) {
            return [];
        }

        try {
            $decoded = json_decode((string) file_get_contents($path), true, 16, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [];
        }

        if (!\is_array($decoded) || !\is_array($decoded['values'] ?? null)) {
            return [];
        }

        $values = [];

        foreach ($decoded['values'] as $name => $value) {
            if (\is_string($name) && \is_string($value) && $value !== '') {
                $values[$name] = $value;
            }
        }

        return $values;
    }

    /**
     * @param array<string, string> $values
     */
    public function save(string $system, array $values): void
    {
        $dir = $this->directory.'/sources';

        if (!is_dir($dir) && !mkdir($dir, 0o775, true) && !is_dir($dir)) {
            throw new \RuntimeException(sprintf('Could not create the import source directory "%s".', $dir));
        }

        $kept = [];

        foreach ($values as $name => $value) {
            $name = trim($name);
            $value = trim($value);

            if ($name !== '' && $value !== '') {
                $kept[$name] = $value;
            }
        }

        file_put_contents(
            $this->path($system),
            json_encode([
                'savedAt' => (new \DateTimeImmutable())->format(\DATE_ATOM),
                'values' => $kept,
            ], \JSON_THROW_ON_ERROR | \JSON_PRETTY_PRINT),
            \LOCK_EX,
        );
    }

    public function forget(string $system): void
    {
        $path = $this->path($system);

        if (is_file($path)) {
            unlink($path);
        }
    }

    public function has(string $system): bool
    {
        return $this->load($system) !== [];
    }

    private function path(string $system): string
    {
        if (preg_match('/^[a-z0-9_]+$/', $system) !== 1) {
            throw new \InvalidArgumentException('That is not an import source id.');
        }

        return $this->directory.'/sources/'.$system.'.json';
    }
}
