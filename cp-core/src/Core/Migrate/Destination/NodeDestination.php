<?php

declare(strict_types=1);

namespace App\Core\Migrate\Destination;

use App\Core\Content\SlugGenerator;
use App\Core\Migrate\MigrationDestinationInterface;
use App\Core\Migrate\MigrationRow;
use App\Core\Revision\Entity\NodeRevision;
use App\Core\Taxonomy\Entity\Term;
use App\Entity\Node;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Writes rows into a Node bundle.
 *
 * The transform is expected to hand over node-shaped keys: title, slug,
 * status, locale, publishedAt, and anything else goes into the hybrid model's
 * JSON data — which is the point of that model, and why an import does not
 * need a schema change per source field the way an EAV or postmeta design does.
 *
 * Slugs are generated when absent and made unique per locale, because a source
 * system's titles collide far more often than its own slug rules admit and
 * uniq_node_slug_locale would otherwise turn row 900 of an import into a
 * constraint violation.
 */
final class NodeDestination implements MigrationDestinationInterface
{
    /** Keys consumed as node columns; everything else becomes JSON data. */
    private const RESERVED = ['title', 'slug', 'status', 'locale', 'publishedAt', 'categoryIds', 'tagIds'];

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly SlugGenerator $slugGenerator,
        private readonly string $type,
        private readonly string $defaultLocale = 'en',
    ) {
    }

    public function describe(): string
    {
        return sprintf('Node bundle "%s"', $this->type);
    }

    public function entityType(): string
    {
        return 'node';
    }

    public function write(MigrationRow $row, ?string $existingId): string
    {
        $node = $existingId === null ? null : $this->entityManager->find(Node::class, (int) $existingId);

        $locale = $row->getString('locale', $this->defaultLocale);
        $title = trim($row->getString('title'));

        if ($title === '') {
            throw new \RuntimeException('A node row needs a non-empty "title"; the column is not nullable.');
        }

        if ($node === null) {
            // The recorded id no longer resolves (someone deleted the node
            // between runs). Creating a replacement is right: the map still
            // claims this source row is imported, and leaving it unimported
            // while the map says otherwise is the one state nobody can debug.
            $node = new Node($title, $this->slugFor($row, $title, $locale, null), $this->type, $locale);
            $this->entityManager->persist($node);
        } else {
            $node->setTitle($title);
            $node->setSlug($this->slugFor($row, $title, $locale, $node->getId()));
        }

        $node->setData($this->dataFrom($row));
        $this->applyTerms($node, $row);

        $status = $row->getString('status', Node::STATUS_DRAFT);

        if ($status === Node::STATUS_PUBLISHED) {
            $node->publish($this->publishedAt($row));
        } else {
            $node->setStatus($status);
        }

        $this->entityManager->flush();

        $id = $node->getId();

        if ($id === null) {
            throw new \RuntimeException('The node was flushed but has no id; the map cannot record this row.');
        }

        return (string) $id;
    }

    public function delete(string $destinationId): bool
    {
        $node = $this->entityManager->find(Node::class, (int) $destinationId);

        if ($node === null) {
            return false;
        }

        $this->removeRevisions($node);

        $this->entityManager->remove($node);
        $this->entityManager->flush();

        return true;
    }

    /**
     * Drops the node's revisions before the node itself.
     *
     * The database would handle this on its own — NodeRevision.node_id is
     * ON DELETE CASCADE — but Doctrine would not. Writing a node makes the
     * revision listener capture a NodeRevision, which stays managed in the unit
     * of work; removing the node then leaves those revisions pointing at an
     * entity Doctrine no longer recognises, and the next flush fails with "a
     * new entity was found through the relationship NodeRevision#node" instead
     * of deleting anything.
     *
     * Nothing in the product had hit this because nothing in the product
     * hard-deletes a Node: the admin surfaces trash it (Node is #[SoftDeletable]).
     * Rollback is the first code that really removes one, so it is the first
     * code that has to keep the in-memory graph consistent too.
     */
    private function removeRevisions(Node $node): void
    {
        /** @var list<NodeRevision> $revisions */
        $revisions = $this->entityManager->getRepository(NodeRevision::class)->findBy(['node' => $node]);

        foreach ($revisions as $revision) {
            $this->entityManager->remove($revision);
        }
    }

    /**
     * Attaches categories and tags, given as CPalius term ids the migration
     * already resolved through MigrationLookup.
     *
     * The lists are treated as the whole truth: terms removed at the source
     * are detached here on the next run, because an import that only ever adds
     * leaves content tagged with things the source says it is not, and no
     * amount of re-running fixes it.
     *
     * A term id that no longer resolves is skipped rather than fatal — losing
     * one tag is not worth losing the post.
     */
    private function applyTerms(Node $node, MigrationRow $row): void
    {
        foreach ([['categoryIds', 'Category'], ['tagIds', 'Tag']] as [$key, $suffix]) {
            if (!$row->has($key)) {
                continue;
            }

            $wanted = [];

            foreach ($this->termIds($row->get($key)) as $id) {
                $term = $this->entityManager->find(Term::class, $id);

                if ($term !== null) {
                    $wanted[$id] = $term;
                }
            }

            $current = $suffix === 'Category' ? $node->getCategories() : $node->getTags();

            foreach ($current->toArray() as $term) {
                if (!isset($wanted[$term->getId()])) {
                    $node->{'remove'.$suffix}($term);
                }
            }

            foreach ($wanted as $term) {
                $node->{'add'.$suffix}($term);
            }
        }
    }

    /**
     * @return list<int>
     */
    private function termIds(mixed $raw): array
    {
        if (!\is_array($raw)) {
            return [];
        }

        $ids = [];

        foreach ($raw as $value) {
            if (is_numeric($value)) {
                $ids[] = (int) $value;
            }
        }

        return $ids;
    }

    private function slugFor(MigrationRow $row, string $title, string $locale, ?int $excludeId): string
    {
        $slug = trim($row->getString('slug'));

        // An explicit slug still goes through the generator so its uniqueness
        // is enforced the same way; the generator is idempotent for a slug that
        // is already free.
        return $this->slugGenerator->generate($slug !== '' ? $slug : $title, $locale, $excludeId);
    }

    private function publishedAt(MigrationRow $row): ?\DateTimeImmutable
    {
        $raw = trim($row->getString('publishedAt'));

        if ($raw === '') {
            return null;
        }

        try {
            return new \DateTimeImmutable($raw);
        } catch (\Exception $e) {
            throw new \RuntimeException(sprintf('Could not read publishedAt "%s": %s', $raw, $e->getMessage()), 0, $e);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function dataFrom(MigrationRow $row): array
    {
        $data = $row->data;

        foreach (self::RESERVED as $key) {
            unset($data[$key]);
        }

        return $data;
    }
}
