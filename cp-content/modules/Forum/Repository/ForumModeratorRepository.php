<?php

declare(strict_types=1);

namespace Modules\Forum\Repository;

use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Modules\Forum\Entity\ForumModerator;
use Modules\Forum\Entity\ForumSection;
use Modules\Forum\ForumModeratorSubjectType;

/** @extends ServiceEntityRepository<ForumModerator> */
final class ForumModeratorRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ForumModerator::class);
    }

    /**
     * @return list<ForumModerator>
     */
    public function findAllWithSection(): array
    {
        return $this->createQueryBuilder('m')
            ->innerJoin('m.section', 's')->addSelect('s')
            ->orderBy('s.sortOrder', 'ASC')
            ->addOrderBy('m.id', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * @return list<ForumModerator>
     */
    public function findForSection(ForumSection $section): array
    {
        return $this->findBy(['section' => $section], ['id' => 'ASC']);
    }

    /**
     * @param list<string> $roleKeys
     *
     * @return list<ForumModerator>
     */
    public function findMatching(User $user, array $roleKeys): array
    {
        $qb = $this->createQueryBuilder('m')
            ->innerJoin('m.section', 's')->addSelect('s');

        $userMatch = $qb->expr()->andX(
            'm.subjectType = :userType',
            'm.subjectId = :uid',
        );
        $qb->setParameter('userType', ForumModeratorSubjectType::User)
            ->setParameter('uid', $user->getId() ?? 0);

        $keys = array_values(array_filter($roleKeys, static fn (string $key): bool => $key !== ''));
        if ($keys !== []) {
            $qb->andWhere($qb->expr()->orX(
                $userMatch,
                $qb->expr()->andX('m.subjectType = :groupType', 'm.subjectKey IN (:keys)'),
            ))
                ->setParameter('groupType', ForumModeratorSubjectType::Group)
                ->setParameter('keys', $keys);
        } else {
            $qb->andWhere($userMatch);
        }

        return $qb->getQuery()->getResult();
    }
}
