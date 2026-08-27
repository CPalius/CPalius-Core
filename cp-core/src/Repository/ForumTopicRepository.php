<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\ForumSection;
use App\Entity\ForumTopic;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ForumTopic>
 */
final class ForumTopicRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ForumTopic::class);
    }

    public function createSectionTopicsQueryBuilder(ForumSection $section, ?User $viewer, bool $hidePrivate): QueryBuilder
    {
        $qb = $this->createQueryBuilder('t')
            ->andWhere('t.section = :section')
            ->setParameter('section', $section)
            ->orderBy('t.sticky', 'DESC')
            ->addOrderBy('t.updatedAt', 'DESC');

        if ($hidePrivate) {
            if ($viewer === null) {
                $qb->andWhere('t.mode = :normal')
                    ->setParameter('normal', ForumTopic::MODE_NORMAL);
            } elseif (!$this->canSeeAllPrivate($viewer)) {
                $qb->andWhere('t.mode = :normal OR (t.mode = :private AND t.firstPoster = :viewer)')
                    ->setParameter('normal', ForumTopic::MODE_NORMAL)
                    ->setParameter('private', ForumTopic::MODE_PRIVATE)
                    ->setParameter('viewer', $viewer);
            }
        }

        return $qb;
    }

    public function countBySection(ForumSection $section): int
    {
        return (int) $this->createQueryBuilder('t')
            ->select('COUNT(t.id)')
            ->andWhere('t.section = :section')
            ->setParameter('section', $section)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Son aktiviteye göre konular (portal / admin).
     *
     * @return list<ForumTopic>
     */
    public function findLatest(int $limit = 10, int $offset = 0): array
    {
        return $this->createPublicTopicListQuery()
            ->orderBy('t.updatedAt', 'DESC')
            ->setFirstResult(max(0, $offset))
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * Yeni açılan konular — oluşturulma tarihine göre.
     *
     * @return list<ForumTopic>
     */
    public function findNewestOpened(int $limit = 10, int $offset = 0): array
    {
        return $this->createPublicTopicListQuery()
            ->orderBy('t.createdAt', 'DESC')
            ->addOrderBy('t.id', 'DESC')
            ->setFirstResult(max(0, $offset))
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * Son cevaplanan konular — en az bir yanıtı olan, son güncellemeye göre.
     *
     * @return list<ForumTopic>
     */
    public function findLatestReplied(int $limit = 10, int $offset = 0): array
    {
        return $this->createPublicTopicListQuery()
            ->andWhere('t.postCount > 1')
            ->orderBy('t.updatedAt', 'DESC')
            ->addOrderBy('t.id', 'DESC')
            ->setFirstResult(max(0, $offset))
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    private function createPublicTopicListQuery(): \Doctrine\ORM\QueryBuilder
    {
        return $this->createQueryBuilder('t')
            ->leftJoin('t.section', 's')->addSelect('s')
            ->leftJoin('t.firstPoster', 'fp')->addSelect('fp')
            ->leftJoin('t.lastPoster', 'lp')->addSelect('lp')
            ->andWhere('t.movedToTopic IS NULL')
            ->andWhere('t.mode = :normal')
            ->setParameter('normal', ForumTopic::MODE_NORMAL);
    }

    /**
     * @return list<ForumTopic>
     */
    public function findPopular(int $limit = 10): array
    {
        return $this->createQueryBuilder('t')
            ->leftJoin('t.section', 's')->addSelect('s')
            ->andWhere('t.movedToTopic IS NULL')
            ->andWhere('t.mode = :normal')
            ->setParameter('normal', ForumTopic::MODE_NORMAL)
            ->orderBy('t.viewCount', 'DESC')
            ->addOrderBy('t.postCount', 'DESC')
            ->addOrderBy('t.updatedAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * Profil/postbit'te gösterilen "açtığı konu" sayısı — özel konular
     * (Cotonti user_postcount hesabındaki gibi) dahil edilmez, aksi halde
     * herkese açık bir profilde başkasının özel konu varlığı sızabilirdi.
     */
    public function countPublicByAuthor(User $author): int
    {
        return (int) $this->createQueryBuilder('t')
            ->select('COUNT(t.id)')
            ->andWhere('t.firstPoster = :author')
            ->andWhere('t.movedToTopic IS NULL')
            ->andWhere('t.mode = :normal')
            ->setParameter('author', $author)
            ->setParameter('normal', ForumTopic::MODE_NORMAL)
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function createPublicByAuthorQueryBuilder(User $author): QueryBuilder
    {
        return $this->createQueryBuilder('t')
            ->andWhere('t.firstPoster = :author')
            ->andWhere('t.movedToTopic IS NULL')
            ->andWhere('t.mode = :normal')
            ->setParameter('author', $author)
            ->setParameter('normal', ForumTopic::MODE_NORMAL)
            ->orderBy('t.createdAt', 'DESC');
    }

    /**
     * Profilde "mesaj yazdığı konular" — açılış mesajı dışında en az bir yanıt
     * bıraktığı veya başkasının açtığı konuya katıldığı kayıtlar.
     */
    public function createRepliedTopicsByAuthorQueryBuilder(User $author): QueryBuilder
    {
        return $this->createQueryBuilder('t')
            ->andWhere('EXISTS (
                SELECT 1 FROM App\Entity\ForumPost p
                WHERE p.topic = t AND p.author = :author
                AND p.createdAt > (
                    SELECT MIN(fp.createdAt) FROM App\Entity\ForumPost fp WHERE fp.topic = t
                )
            )')
            ->andWhere('t.movedToTopic IS NULL')
            ->andWhere('t.mode = :normal')
            ->setParameter('author', $author)
            ->setParameter('normal', ForumTopic::MODE_NORMAL)
            ->orderBy('t.updatedAt', 'DESC');
    }

    public function countRepliedTopicsByAuthor(User $author): int
    {
        return (int) $this->createQueryBuilder('t')
            ->select('COUNT(t.id)')
            ->andWhere('EXISTS (
                SELECT 1 FROM App\Entity\ForumPost p
                WHERE p.topic = t AND p.author = :author
                AND p.createdAt > (
                    SELECT MIN(fp.createdAt) FROM App\Entity\ForumPost fp WHERE fp.topic = t
                )
            )')
            ->andWhere('t.movedToTopic IS NULL')
            ->andWhere('t.mode = :normal')
            ->setParameter('author', $author)
            ->setParameter('normal', ForumTopic::MODE_NORMAL)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * @param int[] $userIds
     *
     * @return array<int, int>
     */
    public function countTopicsForUserIds(array $userIds): array
    {
        if ($userIds === []) {
            return [];
        }

        $rows = $this->createQueryBuilder('t')
            ->select('IDENTITY(t.firstPoster) AS userId, COUNT(t.id) AS cnt')
            ->andWhere('t.firstPoster IN (:userIds)')
            ->andWhere('t.movedToTopic IS NULL')
            ->setParameter('userIds', $userIds)
            ->groupBy('t.firstPoster')
            ->getQuery()
            ->getResult();

        $map = [];
        foreach ($rows as $row) {
            $map[(int) $row['userId']] = (int) $row['cnt'];
        }

        return $map;
    }

    private function canSeeAllPrivate(User $viewer): bool
    {
        return \in_array('admin', $viewer->getCpaliusRoles(), true);
    }
}
