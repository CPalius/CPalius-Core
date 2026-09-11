<?php

declare(strict_types=1);

namespace App\Core\Field;

use App\Core\Resource\ResourceRegistry;
use App\Core\Taxonomy\Entity\Term;
use App\Core\Taxonomy\Entity\Vocabulary;
use App\Core\Taxonomy\VocabularyRegistry;
use App\Entity\Node;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Maps an EntityReference "target" string to a concrete entity class and loads
 * referenced rows. Targets are an allowlist — never a raw class name from input.
 *
 *   user                -> App\Entity\User
 *   node                -> App\Entity\Node (any type)
 *   node:page           -> App\Entity\Node where type = page
 *   term                -> App\Core\Taxonomy\Entity\Term (any vocabulary)
 *   term:tags           -> Term where vocabulary.machine_name = tags
 *   resource:vehicle    -> the #[CpResource(name: 'vehicle')] entity
 */
class ReferenceTargetResolver
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ResourceRegistry $resourceRegistry,
        private readonly VocabularyRegistry $vocabularyRegistry,
    ) {
    }

    /**
     * @return array{class: class-string, nodeType: ?string, termVocabulary: ?string}|null
     */
    public function resolve(string $target): ?array
    {
        $target = trim(strtolower($target));

        if ($target === 'user') {
            return ['class' => User::class, 'nodeType' => null, 'termVocabulary' => null];
        }

        if ($target === 'node') {
            return ['class' => Node::class, 'nodeType' => null, 'termVocabulary' => null];
        }

        if ($target === 'term') {
            return ['class' => Term::class, 'nodeType' => null, 'termVocabulary' => null];
        }

        if (str_starts_with($target, 'node:')) {
            $type = substr($target, 5);

            return preg_match('/^[a-z][a-z0-9_-]{0,49}$/', $type) === 1
                ? ['class' => Node::class, 'nodeType' => $type, 'termVocabulary' => null]
                : null;
        }

        if (str_starts_with($target, 'term:')) {
            $vocabulary = substr($target, 5);

            return preg_match(Vocabulary::MACHINE_NAME_PATTERN, $vocabulary) === 1
                ? ['class' => Term::class, 'nodeType' => null, 'termVocabulary' => $vocabulary]
                : null;
        }

        if (str_starts_with($target, 'resource:')) {
            $definition = $this->resourceRegistry->getByName(substr($target, 9));

            return $definition !== null
                ? ['class' => $definition->entityClass, 'nodeType' => null, 'termVocabulary' => null]
                : null;
        }

        return null;
    }

    public function isValidTarget(string $target): bool
    {
        return $this->resolve($target) !== null;
    }

    public function exists(string $target, int $id): bool
    {
        if ($id < 1) {
            return false;
        }

        $resolved = $this->resolve($target);
        if ($resolved === null) {
            return false;
        }

        if ($resolved['nodeType'] !== null) {
            return (int) $this->entityManager->createQueryBuilder()
                ->select('COUNT(n.id)')
                ->from(Node::class, 'n')
                ->andWhere('n.id = :id')->andWhere('n.type = :type')
                ->setParameter('id', $id)->setParameter('type', $resolved['nodeType'])
                ->getQuery()->getSingleScalarResult() > 0;
        }

        if ($resolved['termVocabulary'] !== null) {
            return (int) $this->entityManager->createQueryBuilder()
                ->select('COUNT(t.id)')
                ->from(Term::class, 't')
                ->join('t.vocabulary', 'v')
                ->andWhere('t.id = :id')->andWhere('v.machineName = :vocab')
                ->setParameter('id', $id)->setParameter('vocab', $resolved['termVocabulary'])
                ->getQuery()->getSingleScalarResult() > 0;
        }

        return $this->entityManager->find($resolved['class'], $id) !== null;
    }

    /**
     * Batch-load referenced rows for one target (Manifesto Law 6.1 — one query).
     *
     * @param list<int> $ids
     *
     * @return array<int, object> id => entity
     */
    public function load(string $target, array $ids): array
    {
        $ids = array_values(array_unique(array_filter($ids, static fn (int $id): bool => $id > 0)));
        if ($ids === []) {
            return [];
        }

        $resolved = $this->resolve($target);
        if ($resolved === null) {
            return [];
        }

        $qb = $this->entityManager->createQueryBuilder()
            ->select('e')
            ->from($resolved['class'], 'e')
            ->andWhere('e.id IN (:ids)')
            ->setParameter('ids', $ids);

        if ($resolved['nodeType'] !== null) {
            $qb->andWhere('e.type = :type')->setParameter('type', $resolved['nodeType']);
        }

        if ($resolved['termVocabulary'] !== null) {
            $qb->join('e.vocabulary', 'v')
                ->andWhere('v.machineName = :vocab')
                ->setParameter('vocab', $resolved['termVocabulary']);
        }

        $out = [];
        foreach ($qb->getQuery()->getResult() as $entity) {
            if (method_exists($entity, 'getId')) {
                $out[(int) $entity->getId()] = $entity;
            }
        }

        return $out;
    }

    /**
     * Selectable targets for the AACP field settings form.
     *
     * @param list<string> $knownNodeTypes
     *
     * @return array<string, string> target => label
     */
    public function allowedTargets(array $knownNodeTypes = []): array
    {
        $targets = ['user' => 'User', 'node' => 'Node (any type)', 'term' => 'Taxonomy term (any vocabulary)'];

        foreach ($knownNodeTypes as $type) {
            if (preg_match('/^[a-z][a-z0-9_-]{0,49}$/', $type) === 1) {
                $targets['node:'.$type] = 'Node: '.$type;
            }
        }

        foreach ($this->vocabularyRegistry->labels() as $machineName => $label) {
            $targets['term:'.$machineName] = 'Term: '.$label;
        }

        foreach ($this->resourceRegistry->all() as $definition) {
            if ($definition->name !== '') {
                $targets['resource:'.$definition->name] = 'Resource: '.$definition->name;
            }
        }

        return $targets;
    }
}
