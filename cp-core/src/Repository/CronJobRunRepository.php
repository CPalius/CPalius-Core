<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\CronJob;
use App\Entity\CronJobRun;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<CronJobRun>
 */
class CronJobRunRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CronJobRun::class);
    }

    /**
     * AACP "Çalıştırma Geçmişi" ekranı için bir işin en son N çalıştırma
     * kaydı — sınırsız geçmiş sayfada birikmesin diye $limit ile kısıtlanır.
     *
     * @return list<CronJobRun>
     */
    public function findRecentByJob(CronJob $cronJob, int $limit = 20): array
    {
        return $this->createQueryBuilder('r')
            ->andWhere('r.cronJob = :cronJob')
            ->setParameter('cronJob', $cronJob)
            ->orderBy('r.startedAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * AACP Dashboard Kritik Uyarı Şeridi'nin tek veri ihtiyacı: bu job'un
     * en son $lookback çalıştırmasını en yeniden geriye doğru tarar, ilk
     * başarılı çalıştırmaya (veya henüz bitmemiş bir çalıştırmaya —
     * isSuccess() null — rastlayınca) durur ve o ana kadar sayılan ardışık
     * başarısızlık sayısını döner. Hiç çalıştırma yoksa veya ilk kayıt
     * zaten başarılıysa 0 döner (fail-safe: "alarm yok" varsayılan durum).
     */
    public function countConsecutiveFailures(CronJob $cronJob, int $lookback = 5): int
    {
        $recentRuns = $this->findRecentByJob($cronJob, $lookback);

        $consecutiveFailures = 0;
        foreach ($recentRuns as $run) {
            if ($run->isSuccess() !== false) {
                break;
            }

            ++$consecutiveFailures;
        }

        return $consecutiveFailures;
    }
}
