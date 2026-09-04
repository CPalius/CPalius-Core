<?php

declare(strict_types=1);

namespace App\Core\Module;

use Throwable;

/**
 * Parsed, immutable module.json contract.
 * Every field is optional: a legacy manifest without the new keys stays valid.
 */
final class ModuleManifest
{
    /**
     * @param array<string, string> $requires Module dir name => version constraint.
     * @param array<string, string> $conflicts Module dir name => version constraint.
     * @param list<string> $provides Free-form capability tags other modules may require.
     */
    public function __construct(
        public readonly string $dirName,
        public readonly string $name,
        public readonly string $version,
        public readonly ?string $bundle,
        public readonly string $description = '',
        public readonly string $author = '',
        public readonly ?string $coreVersion = null,
        public readonly array $requires = [],
        public readonly array $conflicts = [],
        public readonly array $provides = [],
    ) {
    }

    /**
     * Reads <moduleDir>/module.json. Returns null when the file is missing or invalid,
     * so a broken manifest degrades to "undiscoverable" instead of crashing the scan.
     */
    public static function fromDirectory(string $moduleDir): ?self
    {
        $manifestFile = rtrim($moduleDir, '/\\').'/module.json';

        if (!is_file($manifestFile)) {
            return null;
        }

        try {
            $contents = file_get_contents($manifestFile);
            $data = $contents === false ? null : json_decode($contents, true, 32, \JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return null;
        }

        if (!\is_array($data)) {
            return null;
        }

        return self::fromArray(basename(rtrim($moduleDir, '/\\')), $data);
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(string $dirName, array $data): self
    {
        $bundle = $data['bundle'] ?? null;

        return new self(
            dirName: $dirName,
            name: self::stringOr($data['name'] ?? null, $dirName),
            version: self::stringOr($data['version'] ?? null, '0.0.0'),
            bundle: \is_string($bundle) && $bundle !== '' ? $bundle : null,
            description: self::stringOr($data['description'] ?? null, ''),
            author: self::stringOr($data['author'] ?? null, ''),
            coreVersion: \is_string($data['core_version'] ?? null) ? $data['core_version'] : null,
            requires: self::constraintMap($data['requires'] ?? null),
            conflicts: self::constraintMap($data['conflicts'] ?? null),
            provides: self::stringList($data['provides'] ?? null),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'version' => $this->version,
            'description' => $this->description,
            'author' => $this->author,
            'bundle' => $this->bundle,
            'core_version' => $this->coreVersion,
            'requires' => $this->requires,
            'conflicts' => $this->conflicts,
            'provides' => $this->provides,
        ];
    }

    /**
     * Accepts both {"Media": "^1.0"} and ["Media"]; the latter becomes an "any version"
     * constraint so short-hand manifests stay valid.
     *
     * @return array<string, string>
     */
    private static function constraintMap(mixed $raw): array
    {
        if (!\is_array($raw)) {
            return [];
        }

        $map = [];

        foreach ($raw as $key => $value) {
            if (\is_int($key) && \is_string($value) && $value !== '') {
                $map[$value] = '*';
                continue;
            }

            if (\is_string($key) && $key !== '' && \is_string($value) && $value !== '') {
                $map[$key] = $value;
            }
        }

        return $map;
    }

    /**
     * @return list<string>
     */
    private static function stringList(mixed $raw): array
    {
        if (!\is_array($raw)) {
            return [];
        }

        return array_values(array_filter(
            array_map(static fn (mixed $v): string => \is_string($v) ? $v : '', $raw),
            static fn (string $v): bool => $v !== '',
        ));
    }

    private static function stringOr(mixed $raw, string $fallback): string
    {
        return \is_string($raw) && $raw !== '' ? $raw : $fallback;
    }
}
