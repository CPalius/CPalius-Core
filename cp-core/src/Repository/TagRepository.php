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
use Symfony\Component\String\Slugger\AsciiSlugger;

/**
 * Blog tag access over Vocabulary terms (machine_name = blog_tag).
 */
class TagRepository
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

        return $this->isTag($term) ? $term : null;
    }

    public function findOneBySlug(string $slug, string $locale): ?Term
    {
        return $this->terms->findOneBySlug($this->vocabulary(), $slug, $locale);
    }

    public function countAll(): int
    {
        return $this->terms->countByVocabulary($this->vocabulary());
    }

    /**
     * @return list<Term>
     */
    public function findByLocale(string $locale): array
    {
        return $this->terms->findByVocabulary($this->vocabulary(), $locale);
    }

    /**
     * @return list<array{tag: Term, usageCount: int}>
     */
    public function findMostUsed(string $locale, int $limit = 10): array
    {
        $rows = $this->entityManager->createQueryBuilder()
            ->select('t.id AS tagId, COUNT(n.id) AS usageCount')
            ->from(Node::class, 'n')
            ->innerJoin('n.tags', 't')
            ->andWhere('t.locale = :locale')
            ->andWhere('t.vocabulary = :vocabulary')
            ->andWhere('n.status = :status')
            ->setParameter('locale', $locale)
            ->setParameter('vocabulary', $this->vocabulary())
            ->setParameter('status', Node::STATUS_PUBLISHED)
            ->groupBy('t.id')
            ->orderBy('usageCount', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        if ($rows === []) {
            return [];
        }

        $usageCountByTagId = [];
        foreach ($rows as $row) {
            $usageCountByTagId[(int) $row['tagId']] = (int) $row['usageCount'];
        }

        $tags = $this->terms->findByIds(array_keys($usageCountByTagId));

        $result = [];
        foreach ($tags as $tag) {
            if (!$this->isTag($tag)) {
                continue;
            }
            $result[] = ['tag' => $tag, 'usageCount' => $usageCountByTagId[(int) $tag->getId()]];
        }

        usort($result, static fn (array $a, array $b): int => $b['usageCount'] <=> $a['usageCount']);

        return $result;
    }

    /**
     * @param list<string> $names
     *
     * @return list<Term>
     */
    public function findOrCreateByNames(array $names, string $locale): array
    {
        $slugger = new AsciiSlugger($locale);
        $vocabulary = $this->vocabulary();
        $tags = [];

        foreach ($names as $name) {
            $name = trim($name);
            if ($name === '') {
                continue;
            }

            $baseSlug = strtolower($slugger->slug($name)->toString());
            if ($baseSlug === '') {
                continue;
            }

            $tag = $this->terms->findOneBySlug($vocabulary, $baseSlug, $locale);
            if ($tag === null) {
                $tag = new Term($vocabulary, $name, $baseSlug, $locale);
                $this->entityManager->persist($tag);
            }

            $tags[] = $tag;
        }

        return $tags;
    }

    public function vocabulary(): Vocabulary
    {
        $vocabulary = $this->vocabularies->findOneByMachineName(DefaultVocabularies::BLOG_TAG);
        if (!$vocabulary instanceof Vocabulary) {
            throw new \RuntimeException('Vocabulary blog_tag is missing — run migrations / Blog install.');
        }

        return $vocabulary;
    }

    private function isTag(?Term $term): bool
    {
        return $term instanceof Term && $term->getVocabulary()->getMachineName() === DefaultVocabularies::BLOG_TAG;
    }
}
