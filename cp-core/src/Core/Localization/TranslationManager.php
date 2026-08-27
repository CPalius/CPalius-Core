<?php

declare(strict_types=1);

namespace App\Core\Localization;

use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;
use Throwable;

/**
 * AACP "Dil Yönetimi" panelinin tek veri kaynağı: sistemdeki tüm
 * messages+intl-icu.{tr,en}.yaml dosyalarını (çekirdek + modüller) okur,
 * düzleştirilmiş bir TranslationEntry listesi sunar, inline düzenlemeyi
 * ilgili dosyaya geri yazar, ve tam matrisi JSON/YAML olarak
 * dışa/içe aktarır.
 *
 * Core Never Dies: bu servis KENDİSİ bozuksa (bozuk bir YAML dosyası,
 * eksik dizin) sistemin geri kalanını asla etkilemez — her okuma/yazma
 * işlemi kendi try/catch'i içinde izole edilir, hatalar TranslationManagerException
 * olarak fırlatılır ve SADECE controller seviyesinde kullanıcıya
 * flash-mesaj olarak gösterilir; hiçbir zaman uygulamayı çökertmez.
 *
 * Atomik Yazma: her dosya güncellemesi önce bir .tmp dosyasına yazılır,
 * sonra rename() ile hedefin üzerine taşınır — yazma sırasında işlem
 * kesintiye uğrarsa (disk dolması, süreç öldürülmesi) orijinal dosya
 * bozulmadan kalır, ya TAM yeni içerik ya da TAM eski içerik olur.
 */
final class TranslationManager
{
    public function __construct(
        private readonly TranslationFileLocator $locator,
        private readonly Filesystem $filesystem,
    ) {
    }

    /**
     * @return list<TranslationEntry> anahtara göre alfabetik sıralı, tüm gruplar birleştirilmiş
     */
    public function listAll(): array
    {
        $entries = [];

        foreach ($this->locator->locateAll() as $group => $filesByLocale) {
            $trData = $this->safeParseYaml($filesByLocale['tr'] ?? null);
            $enData = $this->safeParseYaml($filesByLocale['en'] ?? null);

            $keys = array_unique([...array_keys($trData), ...array_keys($enData)]);

            foreach ($keys as $key) {
                $entries[] = new TranslationEntry(
                    group: $group,
                    key: $key,
                    tr: $trData[$key] ?? '',
                    en: $enData[$key] ?? '',
                );
            }
        }

        usort($entries, static fn (TranslationEntry $a, TranslationEntry $b): int => $a->key <=> $b->key);

        return $entries;
    }

    /**
     * Tek bir anahtarın tek bir locale'deki değerini günceller. Anahtar
     * o gruptaki dosyada henüz yoksa yeni eklenir; dosya hiç yoksa
     * (henüz hiç çeviri edilmemiş bir modül) sıfırdan oluşturulur.
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
     * @return array<string, array<string, string>> locale => [key => value] — tüm gruplar birleştirilmiş
     */
    public function exportMatrix(): array
    {
        $matrix = ['tr' => [], 'en' => []];

        foreach ($this->listAll() as $entry) {
            $matrix['tr'][$entry->key] = $entry->tr;
            $matrix['en'][$entry->key] = $entry->en;
        }

        return $matrix;
    }

    public function exportAsJson(): string
    {
        $json = json_encode($this->exportMatrix(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

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
     * Dışarıdan yüklenen JSON/YAML içeriğini {tr: {key: value}, en: {key: value}}
     * şeklinde bekler ve HER anahtarı kendi ait olduğu grubun dosyasına
     * geri yazar (mevcut anahtarların grubu korunur; sistemde hiç var
     * olmayan yeni bir anahtar "core" grubuna düşer).
     *
     * Fail-Safe: içerik bu şekle uymuyorsa, tek bir satır bile bozuksa
     * TranslationManagerException fırlatılır ve HİÇBİR dosyaya yazma
     * yapılmaz (ya tamamı ya hiçbiri — kısmi/tutarsız bir import olmaz).
     *
     * @throws TranslationManagerException
     */
    public function importFromString(string $content, string $format): int
    {
        $matrix = $this->parseImportContent($content, $format);

        $existingGroupByKey = [];
        foreach ($this->listAll() as $entry) {
            $existingGroupByKey[$entry->key] = $entry->group;
        }

        /** @var array<string, array<string, array<string, string>>> $byGroupAndLocale group => locale => [key => value] */
        $byGroupAndLocale = [];

        foreach (['tr', 'en'] as $locale) {
            foreach ($matrix[$locale] ?? [] as $key => $value) {
                if (!is_string($key) || !is_string($value)) {
                    throw new TranslationManagerException(sprintf('Geçersiz çeviri girdisi: "%s" alanı metin (string) olmalıdır.', $key));
                }

                $group = $existingGroupByKey[$key] ?? 'core';
                $byGroupAndLocale[$group][$locale][$key] = $value;
            }
        }

        $updatedCount = 0;

        foreach ($byGroupAndLocale as $group => $localeData) {
            foreach ($localeData as $locale => $keyValues) {
                $filePath = $this->resolveFilePathForGroup($group, $locale);
                $data = $this->safeParseYaml($filePath);

                foreach ($keyValues as $key => $value) {
                    $data[$key] = $value;
                    ++$updatedCount;
                }

                ksort($data);
                $this->writeYamlAtomic($filePath, $data);
            }
        }

        return $updatedCount;
    }

    /**
     * @return array{tr: array<string, string>, en: array<string, string>}
     * @throws TranslationManagerException
     */
    private function parseImportContent(string $content, string $format): array
    {
        try {
            $decoded = match ($format) {
                'json' => json_decode($content, true, 512, JSON_THROW_ON_ERROR),
                'yaml' => Yaml::parse($content),
                default => throw new TranslationManagerException(sprintf('Desteklenmeyen içe aktarma formatı: "%s".', $format)),
            };
        } catch (Throwable $e) {
            throw new TranslationManagerException('Dosya okunamadı: içerik geçerli bir '.strtoupper($format).' değil. ('.$e->getMessage().')');
        }

        if (!is_array($decoded) || !isset($decoded['tr']) || !isset($decoded['en'])) {
            throw new TranslationManagerException('Dosya beklenen yapıda değil: en üst seviyede "tr" ve "en" anahtarları olmalıdır.');
        }

        if (!is_array($decoded['tr']) || !is_array($decoded['en'])) {
            throw new TranslationManagerException('"tr" ve "en" alanları anahtar-değer eşlemesi (obje/map) olmalıdır.');
        }

        return ['tr' => $decoded['tr'], 'en' => $decoded['en']];
    }

    private function resolveFilePathForGroup(string $group, string $locale): string
    {
        foreach ($this->locator->locateAll() as $existingGroup => $filesByLocale) {
            if ($existingGroup === $group && isset($filesByLocale[$locale])) {
                return $filesByLocale[$locale];
            }
        }

        if ($group !== 'core' && str_starts_with($group, 'module:')) {
            throw new TranslationManagerException(sprintf('"%s" grubu için "%s" dil dosyası bulunamadı.', $group, $locale));
        }

        return $this->locator->resolveCoreFilePath($locale);
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

        if (!is_array($data)) {
            return [];
        }

        $result = [];
        foreach ($data as $key => $value) {
            if (is_string($key) && is_string($value)) {
                $result[$key] = $value;
            }
        }

        return $result;
    }

    /**
     * @param array<string, string> $data
     * @throws TranslationManagerException
     */
    private function writeYamlAtomic(string $filePath, array $data): void
    {
        $dir = dirname($filePath);

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

    private function assertValidLocale(string $locale): void
    {
        if (!in_array($locale, ['tr', 'en'], true)) {
            throw new TranslationManagerException(sprintf('Geçersiz dil kodu: "%s".', $locale));
        }
    }

    private function assertValidKey(string $key): void
    {
        if (trim($key) === '') {
            throw new TranslationManagerException('Çeviri anahtarı boş olamaz.');
        }
    }
}
