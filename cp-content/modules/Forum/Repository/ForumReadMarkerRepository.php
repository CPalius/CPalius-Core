<?php

declare(strict_types=1);

namespace Modules\Forum\Repository;

use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Modules\Forum\Entity\ForumReadMarker;
use Modules\Forum\Entity\ForumSection;

/** @extends ServiceEntityRepository<ForumReadMarker> */
final class ForumReadMarkerRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ForumReadMarker::class);
    }

    public function findGlobal(User $user): ?ForumReadMarker
    {
        return $this->createQueryBuilder('m')
            ->andWhere('m.user = :user')
            ->andWhere('m.section IS NULL')
            ->setParameter('user', $user)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function findForSection(User $user, ForumSection $section): ?ForumReadMarker
    {
        return $this->findOneBy(['user' => $user, 'section' => $section]);
    }

    /**
     * @return array<int, \DateTimeImmutable> sectionId => markedAt
     */
    public function markedAtBySection(User $user): array
    {
        $rows = $this->createQueryBuilder('m')
            ->andWhere('m.user = :user')
            ->andWhere('m.section IS NOT NULL')
            ->setParameter('user', $user)
            ->getQuery()
            ->getResult();

        $map = [];
        foreach ($rows as $row) {
            $sectionId = $row->getSection()?->getId();
            if ($sectionId !== null) {
                $map[$sectionId] = $row->getMarkedAt();
            }
        }

        return $map;
    }
}
