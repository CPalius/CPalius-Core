<?php

declare(strict_types=1);

namespace App\Core\Plugin;

use App\Entity\Setting;
use App\Repository\SettingRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Faz 4 — AACP "Modül Eklentileri" sayfasının aktif/pasif toggle deposu.
 *
 * Yeni bir tablo/entity YARATMAZ: mevcut App\Entity\Setting (cp_settings
 * tablosu) generic bir key-value store olduğundan, "plugin.{isim}.active"
 * anahtarlarıyla doğrudan kullanılır — tıpkı AACPController::updateSettings()'in
 * #[CpSetting] dışı, elle Setting upsert ettiği desende olduğu gibi.
 *
 * #[CpSetting] attribute mekanizması BİLİNÇLİ OLARAK KULLANILMAZ: o
 * derleme-zamanında SABİT bir tanım listesi taramak için var (bkz.
 * SettingsRegistrationPass) — burada ise plugin isimleri PluginRegistry'den
 * ÇALIŞMA ZAMANINDA gelen dinamik bir küme, derleme-zamanı taramaya uymaz.
 *
 * SettingRepository'yi EXTEND ETMEZ (farklı bir okuma/yazma sözleşmesi
 * sunar — "tüm ayarlar" değil "sadece plugin.* prefix'li ayarlar"), sadece
 * kompozisyonla kullanır.
 */
final class PluginToggleRepository
{
    private const KEY_PREFIX = 'plugin.';
    private const KEY_SUFFIX = '.active';

    public function __construct(
        private readonly SettingRepository $settingRepository,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * Varsayılan (DB'de hiç kayıt yoksa) davranış: AKTİF. Bir plugin
     * SADECE DB'de açıkça '0' olarak işaretlenmişse pasif sayılır — bu,
     * Faz 3'te zaten var olan plugin'lerin bu fazın migration'sız devreye
     * girmesiyle aniden kaybolmamasını garanti eder (fail-safe: "kayıt
     * yok" asla "gizle" anlamına gelmez).
     */
    public function isDisabled(string $pluginName): bool
    {
        $setting = $this->settingRepository->findOneBy(['settingKey' => $this->keyFor($pluginName)]);

        return $setting instanceof Setting && $setting->getSettingValue() === '0';
    }

    /**
     * AACP "Modül Eklentileri" listeleme sayfası için: TÜM plugin toggle
     * durumlarını TEK bir sorguda okur (SettingRepository::findAllAsMap()
     * ile aynı N+1 önleme gerekçesi — Manifesto Law 6.1).
     *
     * @return array<string, bool> pluginName => isActive
     */
    public function findAllStates(): array
    {
        $rows = $this->entityManager->createQueryBuilder()
            ->select('s.settingKey', 's.settingValue')
            ->from(Setting::class, 's')
            ->andWhere('s.settingKey LIKE :prefix')
            ->setParameter('prefix', self::KEY_PREFIX.'%')
            ->getQuery()
            ->getArrayResult();

        $states = [];
        foreach ($rows as $row) {
            $pluginName = $this->pluginNameFromKey($row['settingKey']);
            if ($pluginName === null) {
                continue;
            }

            $states[$pluginName] = $row['settingValue'] !== '0';
        }

        return $states;
    }

    /**
     * Var olan bir Setting satırını günceller, yoksa yeni bir tane
     * persist eder — AACPController::updateSettings()'teki upsert
     * deseniyle birebir aynı. Bilinçli olarak flush() ÇAĞIRMAZ: çağıran
     * controller kendi akışında (CSRF doğrulama, JSON response hazırlama)
     * TEK bir flush ile tutarlılık sağlar.
     */
    public function setActive(string $pluginName, bool $active): void
    {
        $key = $this->keyFor($pluginName);
        $setting = $this->settingRepository->findOneBy(['settingKey' => $key]);

        if (!$setting instanceof Setting) {
            $setting = new Setting($key, 'core');
            $this->entityManager->persist($setting);
        }

        $setting->setSettingValue($active ? '1' : '0');
    }

    private function keyFor(string $pluginName): string
    {
        return self::KEY_PREFIX.$pluginName.self::KEY_SUFFIX;
    }

    private function pluginNameFromKey(string $settingKey): ?string
    {
        if (!str_starts_with($settingKey, self::KEY_PREFIX) || !str_ends_with($settingKey, self::KEY_SUFFIX)) {
            return null;
        }

        return substr($settingKey, strlen(self::KEY_PREFIX), -strlen(self::KEY_SUFFIX));
    }
}
