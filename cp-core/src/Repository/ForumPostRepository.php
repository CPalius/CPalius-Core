<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\ForumPost;
use App\Entity\ForumSection;
use App\Entity\ForumTopic;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ForumPost>
 */
final class ForumPostRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ForumPost::class);
    }

    public function createTopicPostsQueryBuilder(ForumTopic $topic): QueryBuilder
    {
        return $this->createQueryBuilder('p')
            ->andWhere('p.topic = :topic')
            ->setParameter('topic', $topic)
            ->orderBy('p.createdAt', 'ASC');
    }

    public function countByTopic(ForumTopic $topic): int
    {
        return (int) $this->createQueryBuilder('p')
            ->select('COUNT(p.id)')
            ->andWhere('p.topic = :topic')
            ->setParameter('topic', $topic)
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function countBySection(ForumSection $section): int
    {
        return (int) $this->createQueryBuilder('p')
            ->select('COUNT(p.id)')
            ->innerJoin('p.topic', 't')
            ->andWhere('t.section = :section')
            ->setParameter('section', $section)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * @return list<ForumPost>
     */
    public function findLatestPublic(int $limit = 10, int $offset = 0): array
    {
        return $this->createQueryBuilder('p')
            ->innerJoin('p.topic', 't')->addSelect('t')
            ->leftJoin('t.section', 's')->addSelect('s')
            ->leftJoin('p.author', 'a')->addSelect('a')
            ->andWhere('t.mode = :normal')
            ->andWhere('t.movedToTopic IS NULL')
            ->setParameter('normal', ForumTopic::MODE_NORMAL)
            ->orderBy('p.createdAt', 'DESC')
            ->setFirstResult(max(0, $offset))
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    public function findLastByTopic(ForumTopic $topic): ?ForumPost
    {
        return $this->createQueryBuilder('p')
            ->andWhere('p.topic = :topic')
            ->setParameter('topic', $topic)
            ->orderBy('p.createdAt', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function findFirstByTopic(ForumTopic $topic): ?ForumPost
    {
        return $this->createQueryBuilder('p')
            ->andWhere('p.topic = :topic')
            ->setParameter('topic', $topic)
            ->orderBy('p.createdAt', 'ASC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /** @return ForumPost[] */
    public function findLatest(int $limit = 10): array
    {
        return $this->createQueryBuilder('p')
            ->leftJoin('p.topic', 't')->addSelect('t')
            ->orderBy('p.createdAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /** @return ForumPost[] */
    public function findByTopic(ForumTopic $topic): array
    {
        return $this->createQueryBuilder('p')
            ->andWhere('p.topic = :topic')
            ->setParameter('topic', $topic)
            ->getQuery()
            ->getResult();
    }

    /**
     * Profil/postbit "yazdığı cevap" sayısı — özel konulardaki mesajlar
     * hariç (bkz. ForumTopicRepository::countPublicByAuthor docblock'u).
     */
    public function countPublicByAuthor(User $author): int
    {
        return (int) $this->createQueryBuilder('p')
            ->select('COUNT(p.id)')
            ->innerJoin('p.topic', 't')
            ->andWhere('p.author = :author')
            ->andWhere('t.mode = :normal')
            ->setParameter('author', $author)
            ->setParameter('normal', ForumTopic::MODE_NORMAL)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Üye dizini (Studio > Forum > Üyeler) için — mesaj sayısına göre
     * sıralı, sayfalanmış { userId: postCount } haritası. Tek sorguda
     * gruplanır; ardından ForumTopicRepository::countTopicsForUserIds() ile
     * konu sayıları da aynı id kümesi için toplu okunur (N+1 yok).
     *
     * @return array<int, int>
     */
    public function findMemberPostCounts(int $limit, int $offset): array
    {
        $rows = $this->createQueryBuilder('p')
            ->select('IDENTITY(p.author) AS userId, COUNT(p.id) AS cnt')
            ->andWhere('p.author IS NOT NULL')
            ->groupBy('p.author')
            ->orderBy('cnt', 'DESC')
            ->setMaxResults($limit)
            ->setFirstResult($offset)
            ->getQuery()
            ->getResult();

        $map = [];
        foreach ($rows as $row) {
            $map[(int) $row['userId']] = (int) $row['cnt'];
        }

        return $map;
    }

    /**
     * @param int[] $userIds
     *
     * @return array<int, int>
     */
    public function countPublicPostsForUserIds(array $userIds): array
    {
        if ($userIds === []) {
            return [];
        }

        $rows = $this->createQueryBuilder('p')
            ->select('IDENTITY(p.author) AS userId, COUNT(p.id) AS cnt')
            ->innerJoin('p.topic', 't')
            ->andWhere('p.author IN (:userIds)')
            ->andWhere('t.mode = :normal')
            ->setParameter('userIds', $userIds)
            ->setParameter('normal', ForumTopic::MODE_NORMAL)
            ->groupBy('p.author')
            ->getQuery()
            ->getResult();

        $map = [];
        foreach ($rows as $row) {
            $map[(int) $row['userId']] = (int) $row['cnt'];
        }

        return $map;
    }

    public function countDistinctAuthors(): int
    {
        return (int) $this->createQueryBuilder('p')
            ->select('COUNT(DISTINCT p.author)')
            ->andWhere('p.author IS NOT NULL')
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function createPublicByAuthorQueryBuilder(User $author): QueryBuilder
    {
        return $this->createQueryBuilder('p')
            ->innerJoin('p.topic', 't')
            ->andWhere('p.author = :author')
            ->andWhere('t.mode = :normal')
            ->andWhere('t.movedToTopic IS NULL')
            ->setParameter('author', $author)
            ->setParameter('normal', ForumTopic::MODE_NORMAL)
            ->orderBy('p.createdAt', 'DESC');
    }
}
