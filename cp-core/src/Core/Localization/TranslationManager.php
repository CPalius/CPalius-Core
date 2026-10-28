<?php

declare(strict_types=1);

namespace App\Core\Localization;

use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * AACP translation explorer: read/write YAML (core + modules), flatten entries, import/export JSON/YAML.
 * Locales come from LocaleProvider. Writes are atomic (.tmp then rename). Failures are TranslationManagerException only.
 */
final class TranslationManager
{
    public function __construct(
        private readonly TranslationFileLocator $locator,
        private readonly Filesystem $filesystem,
    ) {
    }

    /**
     * Active locales for column headers and validation.
     *
     * @return list<string>
     */
    public function locales(): array
    {
        return $this->locator->locales();
    }

    /**
     * @return list<TranslationEntry> sorted by group then key, all groups merged
     */
    public function listAll(): array
    {
        $locales = $this->locales();
        $entries = [];

        foreach ($this->locator->locateAll() as $group => $filesByLocale) {
            /** @var array<string, array<string, string>> $dataByLocale */
            $dataByLocale = [];
            $keys = [];

            foreach ($locales as $locale) {
                $data = $this->safeParseYaml($filesByLocale[$locale] ?? null);
                $dataByLocale[$locale] = $data;
                $keys = [...$keys, ...array_keys($data)];
            }

            foreach (array_unique($keys) as $key) {
                $values = [];

                foreach ($locales as $locale) {
                    $values[$locale] = $dataByLocale[$locale][$key] ?? '';
                }

                $entries[] = new TranslationEntry(group: $group, key: $key, values: $values);
            }
        }

        usort(
            $entries,
            static fn (TranslationEntry $a, TranslationEntry $b): int => [$a->group, $a->key] <=> [$b->group, $b->key],
        );

        return $entries;
    }

    /**
     * Set one key in one locale. Creates the key or the file if missing.
     *
     * @throws TranslationManagerException
     */
    public function updateValue(string $group, string $key, string $locale, string $value): void
    {
        $this->assertValidLocale($locale);
        $this->assertValidKey($key);

        $filePath = $this->resolveFilePathForGroup($group, $locale);
        $data = $this->safeParseYaml($filePath);
        $data[$key] = $value;
        ksort($data);

        $this->writeYamlAtomic($filePath, $data);
    }

    /**
     * Write many keys of one group/locale with a single file rewrite — the AI
     * translate loop would otherwise parse and rewrite the same YAML per key.
     *
     * @param array<string, string> $values
     *
     * @return int keys written
     *
     * @throws TranslationManagerException
     */
    public function updateMany(string $group, string $locale, array $values): int
    {
        $this->assertValidLocale($locale);

        if ($values === []) {
            return 0;
        }

        $filePath = $this->resolveFilePathForGroup($group, $locale);
        $data = $this->safeParseYaml($filePath);

        foreach ($values as $key => $value) {
            $this->assertValidKey((string) $key);
            $data[(string) $key] = $value;
        }

        ksort($data);
        $this->writeYamlAtomic($filePath, $data);

        return \count($values);
    }

    /**
     * How much of $sourceLocale's text each locale has translated.
     * Keys the source itself leaves empty are not counted: there is nothing to translate.
     *
     * @param list<string> $targetLocales
     *
     * @return array<string, array{total: int, translated: int, percent: int}>
     */
    public function completeness(string $sourceLocale, array $targetLocales): array
    {
        $result = array_fill_keys($targetLocales, ['total' => 0, 'translated' => 0, 'percent' => 0]);

        foreach ($this->locator->locateAll() as $group => $filesByLocale) {
            $source = array_filter($this->safeParseYaml($filesByLocale[$sourceLocale] ?? null), static fn (string $v): bool => trim($v) !== '');
            if ($source === []) {
                continue;
            }

            foreach ($targetLocales as $locale) {
                $target = $this->safeParseYaml($filesByLocale[$locale] ?? $this->locator->resolveFilePath($group, $locale));
                $translated = 0;
                foreach (array_keys($source) as $key) {
                    if (isset($target[$key]) && trim($target[$key]) !== '') {
                        ++$translated;
                    }
                }
                $result[$locale]['total'] += \count($source);
                $result[$locale]['translated'] += $translated;
            }
        }

        foreach ($result as $locale => $row) {
            $result[$locale]['percent'] = $row['total'] > 0 ? (int) floor($row['translated'] * 100 / $row['total']) : 100;
        }

        return $result;
    }

    /**
     * The next strings $locale still lacks, with the source text to translate.
     *
     * @param int $offset how many missing strings to pass over — the ones the provider already failed on this run
     *
     * @return list<array{group: string, key: string, source: string}>
     */
    public function missing(string $locale, string $sourceLocale, int $limit, int $offset = 0): array
    {
        $out = [];
        $seen = 0;

        foreach ($this->locator->locateAll() as $group => $filesByLocale) {
            $source = $this->safeParseYaml($filesByLocale[$sourceLocale] ?? null);
            $target = $this->safeParseYaml($filesByLocale[$locale] ?? null);

            foreach ($source as $key => $text) {
                if (trim($text) === '' || trim($target[$key] ?? '') !== '') {
                    continue;
                }
                if ($seen++ < $offset) {
                    continue;
                }

                $out[] = ['group' => (string) $group, 'key' => $key, 'source' => $text];
                if (\count($out) >= $limit) {
                    return $out;
                }
            }
        }

        return $out;
    }

    /**
     * Export matrix keyed by group then locale (avoids colliding keys across domains).
     *
     * @return array<string, array<string, array<string, string>>>
     */
    public function exportMatrix(): array
    {
        $locales = $this->locales();
        $matrix = [];

        foreach ($this->listAll() as $entry) {
            foreach ($locales as $locale) {
                $matrix[$entry->group][$locale][$entry->key] = $entry->valueFor($locale);
            }
        }

        return $matrix;
    }

    public function exportAsJson(): string
    {
        $json = json_encode($this->exportMatrix(), \JSON_PRETTY_PRINT | \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES);

        if ($json === false) {
            throw new TranslationManagerException('Translation matrix could not be converted to JSON.');
        }

        return $json;
    }

    public function exportAsYaml(): string
    {
        return Yaml::dump($this->exportMatrix(), 4, 2, Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK);
    }

    /**
     * Import JSON/YAML. New format is grouped; legacy flat {tr,en} maps keys to their current group (or core).
     * Invalid payload throws and writes nothing (all-or-nothing).
     *
     * @return int number of key-locale pairs written
     *
     * @throws TranslationManagerException
     */
    public function importFromString(string $content, string $format): int
    {
        $decoded = $this->decode($content, $format);
        $byGroupAndLocale = $this->normalizeImportPayload($decoded);

        // Do not open any file until the whole payload is validated.
        $plan = [];
        $updatedCount = 0;

        foreach ($byGroupAndLocale as $group => $localeData) {
            foreach ($localeData as $locale => $keyValues) {
                $filePath = $this->resolveFilePathForGroup((string) $group, (string) $locale);
                $data = $plan[$filePath] ?? $this->safeParseYaml($filePath);

                foreach ($keyValues as $key => $value) {
                    $data[$key] = $value;
                    ++$updatedCount;
                }

                ksort($data);
                $plan[$filePath] = $data;
            }
        }

        foreach ($plan as $filePath => $data) {
            $this->writeYamlAtomic((string) $filePath, $data);
        }

        return $updatedCount;
    }

    /**
     * @return array<mixed>
     *
     * @throws TranslationManagerException
     */
    private function decode(string $content, string $format): array
    {
        try {
            $decoded = match ($format) {
                'json' => json_decode($content, true, 512, \JSON_THROW_ON_ERROR),
                'yaml' => Yaml::parse($content),
                default => throw new TranslationManagerException(sprintf('Unsupported import format: "%s".', $format)),
            };
        } catch (TranslationManagerException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new TranslationManagerException('Could not read file: content is not valid '.strtoupper($format).'. ('.$e->getMessage().')');
        }

        if (!\is_array($decoded) || $decoded === []) {
            throw new TranslationManagerException('File has unexpected structure: top level must be a key-value map.');
        }

        return $decoded;
    }

    /**
     * Normalize both import shapes into group => locale => [key => value] and validate values.
     *
     * @param array<mixed> $decoded
     *
     * @return array<string, array<string, array<string, string>>>
     *
     * @throws TranslationManagerException
     */
    private function normalizeImportPayload(array $decoded): array
    {
        $locales = $this->locales();
        $isLegacyFlat = false;

        foreach (array_keys($decoded) as $topKey) {
            if (\in_array($topKey, $locales, true)) {
                $isLegacyFlat = true;
                break;
            }
        }

        if ($isLegacyFlat) {
            return $this->normalizeLegacyPayload($decoded);
        }

        $result = [];

        foreach ($decoded as $group => $localeData) {
            if (!\is_string($group) || !\is_array($localeData)) {
                throw new TranslationManagerException('File has unexpected structure: each group must be an object of locale codes.');
            }

            foreach ($localeData as $locale => $keyValues) {
                if (!\is_string($locale) || !\in_array($locale, $locales, true)) {
                    // Skip locale blocks that are not active here (file may come from another install).
                    continue;
                }

                if (!\is_array($keyValues)) {
                    throw new TranslationManagerException(sprintf('Group "%s" block "%s" must be a key-value map.', $group, $locale));
                }

                $result[$group][$locale] = $this->assertKeyValueMap($keyValues);
            }
        }

        if ($result === []) {
            throw new TranslationManagerException('File contains no importable active locale blocks.');
        }

        return $result;
    }

    /**
     * @param array<mixed> $decoded
     *
     * @return array<string, array<string, array<string, string>>>
     *
     * @throws TranslationManagerException
     */
    private function normalizeLegacyPayload(array $decoded): array
    {
        $existingGroupByKey = [];

        foreach ($this->listAll() as $entry) {
            $existingGroupByKey[$entry->key] ??= $entry->group;
        }

        $result = [];

        foreach ($this->locales() as $locale) {
            if (!isset($decoded[$locale])) {
                continue;
            }

            if (!\is_array($decoded[$locale])) {
                throw new TranslationManagerException(sprintf('Field "%s" must be a key-value map.', $locale));
            }

            foreach ($this->assertKeyValueMap($decoded[$locale]) as $key => $value) {
                $group = $existingGroupByKey[$key] ?? $this->locator->defaultGroup();
                $result[$group][$locale][$key] = $value;
            }
        }

        if ($result === []) {
            throw new TranslationManagerException('File contains no importable active locale blocks.');
        }

        return $result;
    }

    /**
     * @param array<mixed> $map
     *
     * @return array<string, string>
     *
     * @throws TranslationManagerException
     */
    private function assertKeyValueMap(array $map): array
    {
        $result = [];

        foreach ($map as $key => $value) {
            if (!\is_string($key) || trim($key) === '') {
                throw new TranslationManagerException('Invalid translation key: keys must be non-empty strings.');
            }

            if (!\is_string($value)) {
                throw new TranslationManagerException(sprintf('Invalid translation entry: field "%s" must be a string.', $key));
            }

            $result[$key] = $value;
        }

        return $result;
    }

    /**
     * @throws TranslationManagerException
     */
    private function resolveFilePathForGroup(string $group, string $locale): string
    {
        foreach ($this->locator->locateAll() as $existingGroup => $filesByLocale) {
            if ($existingGroup === $group && isset($filesByLocale[$locale])) {
                return $filesByLocale[$locale];
            }
        }

        // Group exists but this locale has no file yet (or the group is new): create it on write.
        $path = $this->locator->resolveFilePath($group, $locale);

        if ($path === null) {
            throw new TranslationManagerException(sprintf('Translation group "%s" could not be resolved.', $group));
        }

        return $path;
    }

    /**
     * @return array<string, string>
     */
    private function safeParseYaml(?string $filePath): array
    {
        if ($filePath === null || !is_file($filePath)) {
            return [];
        }

        try {
            $data = Yaml::parseFile($filePath);
        } catch (ParseException) {
            return [];
        }

        if (!\is_array($data)) {
            return [];
        }

        $result = [];

        foreach ($data as $key => $value) {
            if (\is_string($key) && \is_string($value)) {
                $result[$key] = $value;
            }
        }

        return $result;
    }

    /**
     * @param array<string, string> $data
     *
     * @throws TranslationManagerException
     */
    private function writeYamlAtomic(string $filePath, array $data): void
    {
        $dir = \dirname($filePath);

        try {
            if (!is_dir($dir)) {
                $this->filesystem->mkdir($dir);
            }

            $yaml = Yaml::dump($data, 2, 2);
            $tmpPath = $filePath.'.tmp-'.bin2hex(random_bytes(4));

            $this->filesystem->dumpFile($tmpPath, $yaml);
            $this->filesystem->rename($tmpPath, $filePath, true);
        } catch (\Throwable $e) {
            throw new TranslationManagerException(sprintf('Could not write to file "%s": %s', basename($filePath), $e->getMessage()), previous: $e);
        }
    }

    /**
     * Create empty-value copies of existing domain files for a new locale. Existing files are left untouched.
     */
    public function seedLocale(string $locale): void
    {
        $this->assertValidLocale($locale);

        foreach ($this->locator->locateAll() as $group => $filesByLocale) {
            if (isset($filesByLocale[$locale]) && is_file($filesByLocale[$locale])) {
                continue;
            }

            $sourcePath = $filesByLocale[$this->locator->locales()[0] ?? ''] ?? (array_values($filesByLocale)[0] ?? null);
            $keys = array_keys($this->safeParseYaml(\is_string($sourcePath) ? $sourcePath : null));
            $path = $this->locator->resolveFilePath($group, $locale);

            if ($path === null) {
                continue;
            }

            $this->writeYamlAtomic($path, array_fill_keys($keys, ''));
        }
    }

    /**
     * @throws TranslationManagerException
     */
    private function assertValidLocale(string $locale): void
    {
        if (!\in_array($locale, $this->locales(), true)) {
            throw new TranslationManagerException(sprintf('Invalid or inactive locale code: "%s".', $locale));
        }
    }

    /**
     * @throws TranslationManagerException
     */
    private function assertValidKey(string $key): void
    {
        if (trim($key) === '') {
            throw new TranslationManagerException('Translation key cannot be empty.');
        }
    }
}
