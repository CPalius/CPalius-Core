<?php

declare(strict_types=1);

namespace Modules\Forum\Repository;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Modules\Forum\Entity\ForumAnnouncement;
use Modules\Forum\Entity\ForumSection;

/** @extends ServiceEntityRepository<ForumAnnouncement> */
final class ForumAnnouncementRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ForumAnnouncement::class);
    }

    /**
     * @return list<ForumAnnouncement>
     */
    public function findAllForAdmin(): array
    {
        return $this->createQueryBuilder('a')
            ->leftJoin('a.section', 's')->addSelect('s')
            ->orderBy('a.id', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * @return list<ForumAnnouncement>
     */
    public function findActiveForSection(?ForumSection $section): array
    {
        $now = new \DateTimeImmutable();
        $qb = $this->createQueryBuilder('a')
            ->andWhere('a.active = true')
            ->andWhere('a.startsAt IS NULL OR a.startsAt <= :now')
            ->andWhere('a.endsAt IS NULL OR a.endsAt >= :now')
            ->setParameter('now', $now)
            ->orderBy('a.id', 'DESC');

        if ($section instanceof ForumSection) {
            $ids = $section->ancestorIds();
            if ($ids === []) {
                $qb->andWhere('a.section IS NULL');
            } else {
                $qb->andWhere('a.section IS NULL OR IDENTITY(a.section) IN (:ids)')
                    ->setParameter('ids', $ids);
            }
        } else {
            $qb->andWhere('a.section IS NULL');
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * @return list<ForumAnnouncement>
     */
    public function findForSection(?ForumSection $section): array
    {
        return $this->findBy(['section' => $section], ['id' => 'DESC']);
    }
}
