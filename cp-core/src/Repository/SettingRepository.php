<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Setting;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Setting>
 */
class SettingRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Setting::class);
    }

    /**
     * All setting overrides in one query (Law 6.1); lazy-loaded by SettingsRegistry.
     *
     * @return array<string, string>
     */
    public function findAllAsMap(): array
    {
        $rows = $this->createQueryBuilder('s')
            ->select('s.settingKey', 's.settingValue')
            ->getQuery()
            ->getArrayResult();

        $map = [];
        foreach ($rows as $row) {
            if ($row['settingValue'] !== null) {
                $map[$row['settingKey']] = $row['settingValue'];
            }
        }

        return $map;
    }

    /**
     * Load settings by keys in one query (avoids N+1 on module settings screens).
     *
     * @param list<string> $keys
     *
     * @return array<string, Setting>
     */
    public function findIndexedByKeys(array $keys): array
    {
        if ($keys === []) {
            return [];
        }

        /** @var list<Setting> $settings */
        $settings = $this->createQueryBuilder('s')
            ->where('s.settingKey IN (:keys)')
            ->setParameter('keys', $keys)
            ->getQuery()
            ->getResult();

        $indexed = [];
        foreach ($settings as $setting) {
            $indexed[$setting->getSettingKey()] = $setting;
        }

        return $indexed;
    }
}
