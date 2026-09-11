<?php

declare(strict_types=1);

namespace App\Core\Plugin;

use App\Entity\Setting;
use App\Repository\SettingRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * AACP module plugin active/disabled toggles stored as plugin.{name}.active in cp_settings (no new table).
 * Uses Setting via composition, not #[CpSetting] (plugin names are runtime-dynamic from PluginRegistry).
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

    /** Default when no DB row: active. Only explicit '0' disables (fail-safe: missing row ≠ hidden). */
    public function isDisabled(string $pluginName): bool
    {
        $setting = $this->settingRepository->findOneBy(['settingKey' => $this->keyFor($pluginName)]);

        return $setting instanceof Setting && $setting->getSettingValue() === '0';
    }

    /**
     * Loads all plugin toggle states in one query (avoids N+1).
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

    /** Upserts a Setting row (same pattern as AACPController::updateSettings()); does not flush(). */
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
