<?php

declare(strict_types=1);

namespace App\Repository;

use App\Core\Taxonomy\DefaultVocabularies;
use App\Core\Taxonomy\Entity\Term;
use App\Core\Taxonomy\Entity\Vocabulary;
use App\Core\Taxonomy\Repository\TermRepository;
use App\Core\Taxonomy\Repository\VocabularyRepository;
use App\Entity\Node;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Blog category access over Vocabulary terms (machine_name = blog_category).
 * Keeps the historical CategoryRepository API used by Blog/SEO/AACP.
 */
class CategoryRepository
{
    public function __construct(
        private readonly TermRepository $terms,
        private readonly VocabularyRepository $vocabularies,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function find(int|string $id): ?Term
    {
        $term = $this->terms->find((int) $id);

        return $this->isCategory($term) ? $term : null;
    }

    public function findOneBySlug(string $slug, string $locale): ?Term
    {
        return $this->terms->findOneBySlug($this->vocabulary(), $slug, $locale);
    }

    /**
     * The same slug in whichever language happens to have it.
     *
     * Needed because a category URL is shared, bookmarked and indexed without
     * its locale prefix surviving the journey: /en/blog/kategori/duyurular is a
     * Turkish slug requested in English, and the honest answer is "here is the
     * English one", not a 404.
     */
    public function findOneBySlugAnyLocale(string $slug): ?Term
    {
        return $this->terms->findOneBySlugAnyLocale($this->vocabulary(), $slug);
    }

    /**
     * The sibling of $term in $locale, when the two are in a translation group.
     */
    public function findTranslation(Term $term, string $locale): ?Term
    {
        $groupId = $term->getTranslationGroupId();

        if ($groupId === null) {
            return null;
        }

        $translation = $this->terms->findOneByTranslationGroup($groupId, $locale);

        return $this->isCategory($translation) ? $translation : null;
    }

    /**
     * @return list<Term>
     */
    public function findByLocale(string $locale): array
    {
        return $this->terms->findByVocabulary($this->vocabulary(), $locale);
    }

    /**
     * Resolves several ids at once, keeping the vocabulary guard that find()
     * applies to a single id.
     *
     * The guard is not cosmetic: these ids arrive from a submitted form. Without
     * it, a crafted POST carrying a tag's id — or any other vocabulary's term id
     * — would attach that term to a node as if it were a category.
     *
     * @param list<int|string> $ids
     *
     * @return list<Term>
     */
    public function findByIds(array $ids): array
    {
        $ids = array_values(array_filter(array_map(
            static fn (int|string $id): int => (int) $id,
            $ids,
        ), static fn (int $id): bool => $id > 0));

        if ($ids === []) {
            return [];
        }

        return array_values(array_filter(
            $this->terms->findByIds($ids),
            fn (Term $term): bool => $this->isCategory($term),
        ));
    }

    /**
     * Every category across every locale, ordered by name.
     *
     * Used by target pickers that deliberately span locales (the URL alias
     * admin shows the locale next to each name), which is why this cannot be
     * expressed with findByLocale().
     *
     * @return list<Term>
     */
    public function findAllSorted(int $limit = 200): array
    {
        $terms = $this->terms->findByVocabulary($this->vocabulary());

        usort($terms, static fn (Term $a, Term $b): int => strcasecmp($a->getName(), $b->getName()));

        return array_slice($terms, 0, max(1, $limit));
    }

    public function countAll(): int
    {
        return $this->terms->countByVocabulary($this->vocabulary());
    }

    /**
     * @return list<Term>
     */
    public function findTreeByLocale(string $locale): array
    {
        return $this->terms->findTreeByVocabulary($this->vocabulary(), $locale);
    }

    /**
     * @return array<int, int>
     */
    public function countPublishedPostsByLocale(string $type, string $locale): array
    {
        /** @var list<array{id: int, cnt: string|int}> $rows */
        $rows = $this->entityManager->createQueryBuilder()
            ->select('c.id AS id, COUNT(DISTINCT n.id) AS cnt')
            ->from(Node::class, 'n')
            ->innerJoin('n.categories', 'c')
            ->andWhere('n.type = :type')
            ->andWhere('n.locale = :locale')
            ->andWhere('n.status = :status')
            ->andWhere('n.deletedAt IS NULL')
            ->andWhere('c.locale = :locale')
            ->andWhere('c.vocabulary = :vocabulary')
            ->setParameter('type', $type)
            ->setParameter('locale', $locale)
            ->setParameter('status', Node::STATUS_PUBLISHED)
            ->setParameter('vocabulary', $this->vocabulary())
            ->groupBy('c.id')
            ->getQuery()
            ->getArrayResult();

        $counts = [];
        foreach ($rows as $row) {
            $counts[(int) $row['id']] = (int) $row['cnt'];
        }

        return $counts;
    }

    public function vocabulary(): Vocabulary
    {
        $vocabulary = $this->vocabularies->findOneByMachineName(DefaultVocabularies::BLOG_CATEGORY);
        if (!$vocabulary instanceof Vocabulary) {
            throw new \RuntimeException('Vocabulary blog_category is missing — run migrations / Blog install.');
        }

        return $vocabulary;
    }

    private function isCategory(?Term $term): bool
    {
        return $term instanceof Term && $term->getVocabulary()->getMachineName() === DefaultVocabularies::BLOG_CATEGORY;
    }
}
