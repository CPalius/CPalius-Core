<?php

declare(strict_types=1);

namespace Modules\Forum\Repository;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Modules\Forum\Entity\ForumPost;
use Modules\Forum\Entity\ForumPostAttachment;

/** @extends ServiceEntityRepository<ForumPostAttachment> */
final class ForumPostAttachmentRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ForumPostAttachment::class);
    }

    /**
     * @param list<int> $postIds
     *
     * @return array<int, list<ForumPostAttachment>>
     */
    public function findGroupedByPostIds(array $postIds): array
    {
        if ($postIds === []) {
            return [];
        }

        $rows = $this->createQueryBuilder('a')
            ->innerJoin('a.asset', 'asset')->addSelect('asset')
            ->andWhere('IDENTITY(a.post) IN (:ids)')
            ->setParameter('ids', $postIds)
            ->orderBy('a.id', 'ASC')
            ->getQuery()
            ->getResult();

        $map = [];
        foreach ($rows as $row) {
            $postId = $row->getPost()->getId();
            if ($postId === null) {
                continue;
            }
            $map[$postId][] = $row;
        }

        return $map;
    }

    /** @return list<ForumPostAttachment> */
    public function findByPost(ForumPost $post): array
    {
        return $this->createQueryBuilder('a')
            ->innerJoin('a.asset', 'asset')->addSelect('asset')
            ->andWhere('a.post = :post')
            ->setParameter('post', $post)
            ->orderBy('a.id', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
