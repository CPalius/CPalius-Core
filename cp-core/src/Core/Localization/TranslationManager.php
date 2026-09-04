<?php

declare(strict_types=1);

namespace App\Core\Localization;

use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;
use Throwable;

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
     * @return list<TranslationEntry> Sorted by group then key, all groups merged.
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
            throw new TranslationManagerException('Çeviri matrisi JSON formatına dönüştürülemedi.');
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
     * @return int Number of key-locale pairs written.
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
                default => throw new TranslationManagerException(sprintf('Desteklenmeyen içe aktarma formatı: "%s".', $format)),
            };
        } catch (TranslationManagerException $e) {
            throw $e;
        } catch (Throwable $e) {
            throw new TranslationManagerException('Dosya okunamadı: içerik geçerli bir '.strtoupper($format).' değil. ('.$e->getMessage().')');
        }

        if (!\is_array($decoded) || $decoded === []) {
            throw new TranslationManagerException('Dosya beklenen yapıda değil: en üst seviyede bir anahtar-değer eşlemesi (obje/map) olmalıdır.');
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
                throw new TranslationManagerException('Dosya beklenen yapıda değil: her grup, dil kodlarından oluşan bir obje içermelidir.');
            }

            foreach ($localeData as $locale => $keyValues) {
                if (!\is_string($locale) || !\in_array($locale, $locales, true)) {
                    // Skip locale blocks that are not active here (file may come from another install).
                    continue;
                }

                if (!\is_array($keyValues)) {
                    throw new TranslationManagerException(sprintf('"%s" grubunun "%s" bloğu anahtar-değer eşlemesi olmalıdır.', $group, $locale));
                }

                $result[$group][$locale] = $this->assertKeyValueMap($keyValues);
            }
        }

        if ($result === []) {
            throw new TranslationManagerException('Dosyada içe aktarılabilecek hiçbir aktif dil bloğu bulunamadı.');
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
                throw new TranslationManagerException(sprintf('"%s" alanı anahtar-değer eşlemesi (obje/map) olmalıdır.', $locale));
            }

            foreach ($this->assertKeyValueMap($decoded[$locale]) as $key => $value) {
                $group = $existingGroupByKey[$key] ?? $this->locator->defaultGroup();
                $result[$group][$locale][$key] = $value;
            }
        }

        if ($result === []) {
            throw new TranslationManagerException('Dosyada içe aktarılabilecek hiçbir aktif dil bloğu bulunamadı.');
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
                throw new TranslationManagerException('Geçersiz çeviri anahtarı: anahtarlar boş olmayan metinler olmalıdır.');
            }

            if (!\is_string($value)) {
                throw new TranslationManagerException(sprintf('Geçersiz çeviri girdisi: "%s" alanı metin (string) olmalıdır.', $key));
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
            throw new TranslationManagerException(sprintf('"%s" çeviri grubu çözümlenemedi.', $group));
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
        } catch (Throwable $e) {
            throw new TranslationManagerException(sprintf('"%s" dosyasına yazılamadı: %s', basename($filePath), $e->getMessage()), previous: $e);
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
            throw new TranslationManagerException(sprintf('Geçersiz veya aktif olmayan dil kodu: "%s".', $locale));
        }
    }

    /**
     * @throws TranslationManagerException
     */
    private function assertValidKey(string $key): void
    {
        if (trim($key) === '') {
            throw new TranslationManagerException('Çeviri anahtarı boş olamaz.');
        }
    }
}
