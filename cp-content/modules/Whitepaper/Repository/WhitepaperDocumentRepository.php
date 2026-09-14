<?php

declare(strict_types=1);

namespace Modules\Whitepaper\Repository;

use Modules\Whitepaper\Entity\WhitepaperDocument;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<WhitepaperDocument>
 */
class WhitepaperDocumentRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, WhitepaperDocument::class);
    }

    public function findOneByLocale(string $locale): ?WhitepaperDocument
    {
        return $this->findOneBy(['locale' => $locale]);
    }

    /**
     * @return list<string> locales that have a document header
     */
    public function locales(): array
    {
        $rows = $this->createQueryBuilder('d')
            ->select('DISTINCT d.locale AS locale')
            ->orderBy('d.locale', 'ASC')
            ->getQuery()
            ->getArrayResult();

        return array_values(array_map(static fn (array $row): string => (string) $row['locale'], $rows));
    }
}
