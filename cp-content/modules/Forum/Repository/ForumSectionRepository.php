<?php

declare(strict_types=1);

namespace Modules\Forum\Repository;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Modules\Forum\Entity\ForumSection;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<ForumSection>
 */
final class ForumSectionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ForumSection::class);
    }

    public function findOneBySlugAndLocale(string $slug, string $locale): ?ForumSection
    {
        return $this->findOneBy(['slug' => $slug, 'locale' => $locale]);
    }

    /**
     * @return list<ForumSection>
     */
    public function findRootSectionsByLocale(string $locale): array
    {
        return $this->createQueryBuilder('s')
            ->andWhere('s.locale = :locale')
            ->andWhere('s.parent IS NULL')
            ->setParameter('locale', $locale)
            ->orderBy('s.sortOrder', 'ASC')
            ->addOrderBy('s.title', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * @return list<ForumSection>
     */
    public function findAllByLocale(string $locale): array
    {
        return $this->createQueryBuilder('s')
            ->leftJoin('s.parent', 'p')->addSelect('p')
            ->andWhere('s.locale = :locale')
            ->setParameter('locale', $locale)
            ->orderBy('s.sortOrder', 'ASC')
            ->addOrderBy('s.title', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Sibling nodes under the same parent (reordering).
     *
     * @return list<ForumSection>
     */
    public function findSiblings(ForumSection $section): array
    {
        $qb = $this->createQueryBuilder('s')
            ->andWhere('s.locale = :locale')
            ->setParameter('locale', $section->getLocale())
            ->orderBy('s.sortOrder', 'ASC')
            ->addOrderBy('s.title', 'ASC');

        if ($section->getParent() === null) {
            $qb->andWhere('s.parent IS NULL');
        } else {
            $qb->andWhere('s.parent = :parent')
                ->setParameter('parent', $section->getParent());
        }

        return $qb->getQuery()->getResult();
    }

    public function countDirectChildren(ForumSection $section): int
    {
        return (int) $this->createQueryBuilder('s')
            ->select('COUNT(s.id)')
            ->andWhere('s.parent = :parent')
            ->setParameter('parent', $section)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * @return list<string>
     */
    public function findDirectChildTitles(ForumSection $section): array
    {
        $rows = $this->createQueryBuilder('s')
            ->select('s.title')
            ->andWhere('s.parent = :parent')
            ->setParameter('parent', $section)
            ->orderBy('s.sortOrder', 'ASC')
            ->addOrderBy('s.title', 'ASC')
            ->getQuery()
            ->getScalarResult();

        return array_map(static fn (array $row) => (string) $row['title'], $rows);
    }

    public function findOneByCodeAndLocale(string $code, string $locale): ?ForumSection
    {
        return $this->findOneBy(['code' => $code, 'locale' => $locale]);
    }

    public function findTranslation(Uuid $groupId, string $locale): ?ForumSection
    {
        return $this->findOneBy(['translationGroupId' => $groupId, 'locale' => $locale]);
    }

    public function findLocaleSibling(ForumSection $section, string $locale): ?ForumSection
    {
        if ($section->getLocale() === $locale) {
            return $section;
        }

        $groupId = $section->getTranslationGroupId();
        if (!$groupId instanceof Uuid) {
            return null;
        }

        return $this->findTranslation($groupId, $locale);
    }

    /**
     * All section ids in the same translation group (or just this row).
     *
     * @return list<int>
     */
    public function findGroupSectionIds(ForumSection $section): array
    {
        $id = $section->getId();
        if ($id === null) {
            return [];
        }

        $groupId = $section->getTranslationGroupId();
        if (!$groupId instanceof Uuid) {
            return [$id];
        }

        $rows = $this->createQueryBuilder('s')
            ->select('s.id')
            ->andWhere('s.translationGroupId = :groupId')
            ->setParameter('groupId', $groupId)
            ->getQuery()
            ->getScalarResult();

        $ids = array_values(array_filter(
            array_map(static fn (array $row): int => (int) $row['id'], $rows),
            static fn (int $sectionId): bool => $sectionId > 0,
        ));

        return $ids !== [] ? $ids : [$id];
    }
}
