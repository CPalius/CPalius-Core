<?php

declare(strict_types=1);

namespace Modules\Forum\Repository;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Modules\Forum\Entity\ForumLinkPreview;

/** @extends ServiceEntityRepository<ForumLinkPreview> */
final class ForumLinkPreviewRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ForumLinkPreview::class);
    }

    public function findOneByHash(string $hash): ?ForumLinkPreview
    {
        return $this->findOneBy(['urlHash' => $hash]);
    }
}
