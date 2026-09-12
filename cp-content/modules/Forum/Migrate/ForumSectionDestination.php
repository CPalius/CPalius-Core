<?php

declare(strict_types=1);

namespace Modules\Forum\Migrate;

use App\Core\Migrate\MigrationDestinationInterface;
use App\Core\Migrate\MigrationRow;
use Doctrine\ORM\EntityManagerInterface;
use Modules\Forum\Entity\ForumSection;
use Modules\Forum\ForumNodeType;
use Modules\Forum\ForumSectionType;

/**
 * Writes imported forums and categories into forum sections.
 *
 * Lives in the Forum module because Forum owns ForumSection and what a valid
 * one is; an importer knows what a XenForo node looks like and has no business
 * knowing this entity's rules. The next forum importer reuses this rather than
 * discovering the same invariants again.
 *
 * The parent is resolved through the map as a CPalius id, not by matching
 * names. Forum trees have sibling categories called "General" at several
 * levels, and joining two of them because they share a label rearranges a
 * board in a way nobody notices until someone goes looking for a thread.
 */
final class ForumSectionDestination implements MigrationDestinationInterface
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly string $defaultLocale = 'en',
    ) {
    }

    public function describe(): string
    {
        return 'Forum sections';
    }

    public function entityType(): string
    {
        return 'forum_section';
    }

    public function write(MigrationRow $row, ?string $existingId): string
    {
        $title = trim($row->getString('title'));

        if ($title === '') {
            throw new \RuntimeException('A forum section needs a "title".');
        }

        $locale = $row->getString('locale', $this->defaultLocale);
        $code = $this->code($row, $title);
        $slug = $this->slug($row, $title, $code);

        $section = $existingId === null ? null : $this->entityManager->find(ForumSection::class, (int) $existingId);

        // Matching on the code keeps a re-import from creating a second copy of
        // a section somebody has since renamed.
        $section ??= $this->entityManager->getRepository(ForumSection::class)->findOneBy(['code' => $code]);

        if ($section === null) {
            $section = new ForumSection($code, $slug, $locale, $title);
            $this->entityManager->persist($section);
        } else {
            $section->setSlug($slug);
            $section->setLocale($locale);
            $section->setTitle($title);
        }

        $description = trim($row->getString('description'));
        $section->setDescription($description === '' ? null : $description);

        $sortOrder = $row->getString('sortOrder');
        if (is_numeric($sortOrder)) {
            $section->setSortOrder((int) $sortOrder);
        }

        $nodeType = $this->nodeType($row);
        $section->setNodeType($nodeType);
        $section->setSectionType($nodeType === ForumNodeType::Category ? ForumSectionType::Category : ForumSectionType::Subcategory);
        $section->setIsContainer($nodeType === ForumNodeType::Category);
        $section->setAllowTopics($nodeType === ForumNodeType::Forum && trim($row->getString('allowTopics', '1')) !== '0');

        $linkUrl = trim($row->getString('linkUrl'));
        $section->setLinkUrl($nodeType === ForumNodeType::Link && $linkUrl !== '' ? $linkUrl : null);

        $section->setParent($this->parent($row, $section));

        $this->entityManager->flush();

        $id = $section->getId();

        if ($id === null) {
            throw new \RuntimeException('The forum section was flushed but has no id; the map cannot record this row.');
        }

        return (string) $id;
    }

    public function delete(string $destinationId): bool
    {
        $section = $this->entityManager->find(ForumSection::class, (int) $destinationId);

        if ($section === null) {
            return false;
        }

        $this->entityManager->remove($section);
        $this->entityManager->flush();

        return true;
    }

    /**
     * A section that is not imported yet leaves this one at the root: source
     * exports are not ordered parents-first, and a second run — which costs
     * nothing, because unchanged rows are skipped — puts it in place.
     */
    private function parent(MigrationRow $row, ForumSection $section): ?ForumSection
    {
        $parentId = trim($row->getString('parentId'));

        if ($parentId === '' || !is_numeric($parentId) || (int) $parentId === $section->getId()) {
            return null;
        }

        return $this->entityManager->find(ForumSection::class, (int) $parentId);
    }

    private function nodeType(MigrationRow $row): ForumNodeType
    {
        return match (strtolower(trim($row->getString('nodeType')))) {
            'category' => ForumNodeType::Category,
            'link' => ForumNodeType::Link,
            default => ForumNodeType::Forum,
        };
    }

    /**
     * The code carries where the section came from, so two imports from two
     * boards cannot collide on a shared name like "general".
     */
    private function code(MigrationRow $row, string $title): string
    {
        $code = trim($row->getString('code'));

        return $code !== '' ? $code : $this->slugify($title);
    }

    private function slug(MigrationRow $row, string $title, string $code): string
    {
        $slug = trim($row->getString('slug'));

        if ($slug !== '') {
            return $slug;
        }

        $fromTitle = $this->slugify($title);

        return $fromTitle !== '' ? $fromTitle : $code;
    }

    private function slugify(string $value): string
    {
        $slug = strtolower(trim((string) preg_replace('/[^\p{L}\p{N}]+/u', '-', $value), '-'));

        return $slug === '' ? 's-'.substr(bin2hex(random_bytes(4)), 0, 8) : $slug;
    }
}
