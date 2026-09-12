<?php

declare(strict_types=1);

namespace App\Core\Migrate\Destination;

use App\Core\Migrate\MigrationDestinationInterface;
use App\Core\Migrate\MigrationRow;
use App\Core\Taxonomy\Entity\Term;
use App\Core\Taxonomy\Entity\Vocabulary;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Writes rows into a taxonomy vocabulary.
 *
 * The vocabulary is created on first use rather than required to exist: an
 * import that stops to say "create a vocabulary called blog_tag first" has
 * turned a one-command job into a documentation-reading job, and the machine
 * name is already in the migration.
 *
 * Parents are resolved through the map, not by name. Source systems number
 * their terms and reference the number; matching by name instead would join
 * two unrelated branches that happen to share a label, and hierarchy damage is
 * the kind that surfaces months later as content filed under the wrong tree.
 * A parent that is not imported yet leaves the term at the root — source
 * exports are not ordered parents-first, and refusing the row would lose it.
 */
final class TermDestination implements MigrationDestinationInterface
{
    private const RESERVED = ['name', 'slug', 'locale', 'description', 'parentId', 'weight'];

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly string $vocabularyMachineName,
        private readonly string $vocabularyLabel,
        private readonly string $defaultLocale = 'en',
    ) {
    }

    public function describe(): string
    {
        return sprintf('Taxonomy vocabulary "%s"', $this->vocabularyMachineName);
    }

    public function entityType(): string
    {
        return 'term';
    }

    public function write(MigrationRow $row, ?string $existingId): string
    {
        $name = trim($row->getString('name'));

        if ($name === '') {
            throw new \RuntimeException('A term row needs a "name".');
        }

        $locale = $row->getString('locale', $this->defaultLocale);
        $vocabulary = $this->vocabulary();
        $slug = $this->slugFor($row, $name);

        $term = $existingId === null ? null : $this->entityManager->find(Term::class, (int) $existingId);

        // cp_terms is unique on (vocabulary, slug, locale); matching an existing
        // term keeps a re-import from failing on that index.
        $term ??= $this->entityManager->getRepository(Term::class)
            ->findOneBy(['vocabulary' => $vocabulary, 'slug' => $slug, 'locale' => $locale]);

        if ($term === null) {
            $term = new Term($vocabulary, $name, $slug, $locale);
            $this->entityManager->persist($term);
        } else {
            $term->setName($name);
            $term->setSlug($slug);
            $term->setLocale($locale);
        }

        $description = trim($row->getString('description'));
        $term->setDescription($description === '' ? null : $description);

        $weight = $row->getString('weight');
        if (is_numeric($weight)) {
            $term->setWeight((int) $weight);
        }

        $term->setParent($this->parentFor($row, $term));
        $term->setFieldableData($this->dataFrom($row));

        $this->entityManager->flush();

        $id = $term->getId();

        if ($id === null) {
            throw new \RuntimeException('The term was flushed but has no id; the map cannot record this row.');
        }

        return (string) $id;
    }

    public function delete(string $destinationId): bool
    {
        $term = $this->entityManager->find(Term::class, (int) $destinationId);

        if ($term === null) {
            return false;
        }

        $this->entityManager->remove($term);
        $this->entityManager->flush();

        return true;
    }

    private function vocabulary(): Vocabulary
    {
        $vocabulary = $this->entityManager->getRepository(Vocabulary::class)
            ->findOneBy(['machineName' => $this->vocabularyMachineName]);

        if ($vocabulary instanceof Vocabulary) {
            return $vocabulary;
        }

        $vocabulary = new Vocabulary($this->vocabularyMachineName, $this->vocabularyLabel);
        $this->entityManager->persist($vocabulary);
        $this->entityManager->flush();

        return $vocabulary;
    }

    /**
     * The parent is given as a CPalius term id the migration already resolved
     * through MigrationLookup; a term is never its own parent, which a source
     * export can assert and a self-referencing row would otherwise persist.
     */
    private function parentFor(MigrationRow $row, Term $term): ?Term
    {
        $parentId = trim($row->getString('parentId'));

        if ($parentId === '' || !is_numeric($parentId)) {
            return null;
        }

        if ((int) $parentId === $term->getId()) {
            return null;
        }

        return $this->entityManager->find(Term::class, (int) $parentId);
    }

    private function slugFor(MigrationRow $row, string $name): string
    {
        $slug = trim($row->getString('slug'));

        if ($slug !== '') {
            return $slug;
        }

        $generated = strtolower(trim((string) preg_replace('/[^\p{L}\p{N}]+/u', '-', $name), '-'));

        return $generated === '' ? 't-'.substr(bin2hex(random_bytes(4)), 0, 8) : $generated;
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
