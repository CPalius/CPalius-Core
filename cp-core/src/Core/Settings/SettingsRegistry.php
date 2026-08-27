<?php

declare(strict_types=1);

namespace App\Core\Settings;

use App\Repository\SettingRepository;

/**
 * #[CpSetting] ile tanımlanmış TÜM ayarların tek doğruluk kaynağı.
 *
 * ResourceRegistry'nin aksine bu registry tamamen pasif DEĞİLDİR: tanımlar
 * (key, label, type, default, variants) derleme zamanından gelir, ama
 * DEĞERLER veritabanından okunur. Bu okuma LAZY'dir — SettingsRegistry
 * inşa edildiğinde hiçbir sorgu çalışmaz, sadece get()/all() ile bir
 * değere gerçekten ihtiyaç duyulduğunda (ve o zaman bile TEK bir sorguyla,
 * bkz. SettingRepository::findAllAsMap()) DB'ye gidilir — Manifesto
 * Law 6.1 (gereksiz query bütçesi) ile uyumlu: ayarlara hiç dokunmayan
 * sayfalar bu servis için sıfır DB maliyeti öder.
 */
final class SettingsRegistry
{
    /** @var array<string, SettingDefinition> */
    private array $definitions = [];

    /** @var array<string, string>|null lazy yüklenmiş DB override'ları */
    private ?array $values = null;

    public function __construct(
        private readonly SettingRepository $repository,
    ) {
    }

    public function addDefinition(SettingDefinition $definition): void
    {
        $this->definitions[$definition->key] = $definition;
    }

    /**
     * @return list<SettingDefinition>
     */
    public function all(): array
    {
        return array_values($this->definitions);
    }

    public function getDefinition(string $key): ?SettingDefinition
    {
        return $this->definitions[$key] ?? null;
    }

    /**
     * Fail-safe: tanımı olmayan bir key için null döner (exception atmaz).
     * DB'de override yoksa tanımın $default'unu döner; varsa tanımın
     * $type'ına göre cast edilmiş (checkbox->bool, integer->int) değeri.
     */
    public function get(string $key): mixed
    {
        $definition = $this->definitions[$key] ?? null;
        if ($definition === null) {
            return null;
        }

        $raw = $this->loadValues()[$key] ?? null;
        if ($raw === null) {
            return $definition->default;
        }

        return $this->castValue($raw, $definition->type);
    }

    /**
     * @return array<string, string>
     */
    private function loadValues(): array
    {
        return $this->values ??= $this->repository->findAllAsMap();
    }

    /**
     * cp_settings güncellendikten sonra önbelleği temizler; aksi halde aynı
     * istek veya redirect sonrası eski değerler döner.
     */
    public function clearCache(): void
    {
        $this->values = null;
    }

    private function castValue(string $raw, string $type): mixed
    {
        return match ($type) {
            'checkbox', 'boolean' => $raw === '1',
            'integer' => (int) $raw,
            default => $raw,
        };
    }
}
