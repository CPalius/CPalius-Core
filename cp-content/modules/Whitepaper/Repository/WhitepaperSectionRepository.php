<?php

declare(strict_types=1);

namespace Modules\Whitepaper\Repository;

use Modules\Whitepaper\Entity\WhitepaperSection;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<WhitepaperSection>
 */
class WhitepaperSectionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, WhitepaperSection::class);
    }

    /**
     * Ordered chapters for one locale.
     *
     * The slug is the tie-breaker so two chapters left on the same weight
     * still render in a stable order — a page whose sections shuffle between
     * requests would make its own anchors look broken.
     *
     * @return list<WhitepaperSection>
     */
    public function orderedFor(string $locale): array
    {
        /** @var list<WhitepaperSection> $rows */
        $rows = $this->createQueryBuilder('s')
            ->andWhere('s.locale = :locale')
            ->setParameter('locale', $locale)
            ->orderBy('s.weight', 'ASC')
            ->addOrderBy('s.slug', 'ASC')
            ->getQuery()
            ->getResult();

        return $rows;
    }

    /**
     * @return list<string> locales that have at least one chapter
     */
    public function locales(): array
    {
        $rows = $this->createQueryBuilder('s')
            ->select('DISTINCT s.locale AS locale')
            ->orderBy('s.locale', 'ASC')
            ->getQuery()
            ->getArrayResult();

        return array_values(array_map(static fn (array $row): string => (string) $row['locale'], $rows));
    }

    public function findOneBySlug(string $locale, string $slug): ?WhitepaperSection
    {
        return $this->findOneBy(['locale' => $locale, 'slug' => $slug]);
    }
}
