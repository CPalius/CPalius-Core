<?php

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
     * Tüm ayar override'larını TEK bir sorguda okur (Manifesto Law 6.1
     * ruhu: N adet ayrı findOneBy() yerine tek SELECT). SettingsRegistry
     * bu haritayı lazy olarak, sadece ilk get() çağrısında istemesi
     * bekleniyor, bkz. SettingsRegistry::loadValues().
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
     * Verilen anahtarlar için Setting entity'lerini TEK sorguda yükler.
     * Modül ayar güncelleme ekranlarında döngü içi findOneBy() N+1'ini
     * önlemek için kullanılır (bkz. ForumSettingsAdminController::update).
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
