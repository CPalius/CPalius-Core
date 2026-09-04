<?php

declare(strict_types=1);

namespace Modules\Forum\Service;

use Modules\Forum\Entity\ForumSection;
use Modules\Forum\Repository\ForumSectionRepository;
use App\Repository\UserRepository;
use Modules\Forum\ForumSectionType;

/**
 * Resolves Forum > Division > Category > Subcategory hierarchy.
 */
final class ForumSectionHierarchyService
{
    private ?string $loadedLocale = null;

    /** @var array<int, ForumSection> */
    private array $sectionsById = [];

    /** @var array<int, list<ForumSection>> */
    private array $childrenByParentId = [];

    /** @var array<int, int|null> */
    private array $parentIdBySectionId = [];

    public function __construct(
        private readonly ForumSectionRepository $sectionRepository,
        private readonly UserRepository $userRepository,
    ) {
    }

    /**
     * @return list<ForumSection>
     */
    public function getAllSections(string $locale): array
    {
        $this->ensureLocaleLoaded($locale);

        return array_values($this->sectionsById);
    }

    /** @return array{topics: int, posts: int, users: int} */
    public function aggregateStats(string $locale): array
    {
        $topics = 0;
        $posts = 0;

        foreach ($this->getAllSections($locale) as $section) {
            $topics += $section->getTopicCount();
            $posts += $section->getPostCount();
        }

        return [
            'topics' => $topics,
            'posts' => $posts,
            'users' => $this->userRepository->countAll(),
        ];
    }

    /**
     * @return list<ForumSection> Subcategories that can receive a moved topic
     */
    public function getTopicBoards(string $locale, ?ForumSection $exclude = null): array
    {
        return array_values(array_filter(
            $this->getAllSections($locale),
            static function (ForumSection $section) use ($exclude): bool {
                if (!$section->allowsTopics()) {
                    return false;
                }

                if ($exclude !== null && $section->getId() === $exclude->getId()) {
                    return false;
                }

                return true;
            },
        ));
    }

    /**
     * Load all sections for the locale in one query and index children by parent_id (avoids lazy-load N+1).
     */
    private function ensureLocaleLoaded(string $locale): void
    {
        if ($this->loadedLocale === $locale) {
            return;
        }

        $this->sectionsById = [];
        $this->childrenByParentId = [];
        $this->parentIdBySectionId = [];

        foreach ($this->sectionRepository->findAllByLocale($locale) as $section) {
            $id = $section->getId();
            if ($id === null) {
                continue;
            }

            $this->sectionsById[$id] = $section;
            $this->parentIdBySectionId[$id] = $section->getParent()?->getId();
        }

        foreach ($this->sectionsById as $id => $section) {
            $parentId = $this->parentIdBySectionId[$id] ?? 0;
            $this->childrenByParentId[$parentId][] = $section;
        }

        foreach ($this->childrenByParentId as &$children) {
            usort(
                $children,
                static fn (ForumSection $a, ForumSection $b) => $a->getSortOrder() <=> $b->getSortOrder()
                    ?: strcmp($a->getTitle(), $b->getTitle()),
            );
        }
        unset($children);

        $this->loadedLocale = $locale;
    }

    /**
     * @return list<ForumSection>
     */
    private function getChildrenOf(ForumSection $section): array
    {
        $this->ensureLocaleLoaded($section->getLocale());
        $id = $section->getId() ?? 0;

        return $this->childrenByParentId[$id] ?? [];
    }

    /**
     * @return list<ForumSection>
     */
    public function getDivisions(string $locale): array
    {
        $this->ensureLocaleLoaded($locale);

        return array_values(array_filter(
            $this->childrenByParentId[0] ?? [],
            fn (ForumSection $s) => $this->resolveEffectiveType($s) === ForumSectionType::Division,
        ));
    }

    /**
     * @return list<ForumSection>
     */
    public function getSortedChildren(ForumSection $section, ?ForumSectionType $type = null): array
    {
        $children = $this->getChildrenOf($section);

        if ($type === null) {
            return $children;
        }

        return array_values(array_filter($children, fn (ForumSection $s) => $this->resolveEffectiveType($s) === $type));
    }

    /**
     * @return list<array{division: ForumSection, categories: list<array{category: ForumSection, subcategories: list<ForumSection>}>}>
     */
    public function buildIndexTree(string $locale): array
    {
        $tree = [];

        foreach ($this->getDivisions($locale) as $division) {
            $categories = [];
            foreach ($this->getSortedChildren($division, ForumSectionType::Category) as $category) {
                $categories[] = [
                    'category' => $category,
                    'subcategories' => $this->getSortedChildren($category, ForumSectionType::Subcategory),
                ];
            }

            $directSubcategories = $this->getSortedChildren($division, ForumSectionType::Subcategory);

            $tree[] = [
                'division' => $division,
                'categories' => $categories,
                'directSubcategories' => $directSubcategories,
            ];
        }

        return $tree;
    }

    /**
     * indexTree payload for a single division/category page.
     *
     * @return list<array{division: ForumSection, categories: list<array{category: ForumSection, subcategories: list<ForumSection>}>, directSubcategories: list<ForumSection>}>
     */
    public function buildSectionTree(ForumSection $section): array
    {
        if ($section->isCategory()) {
            return [[
                'division' => $section->getParent() ?? $section,
                'categories' => [[
                    'category' => $section,
                    'subcategories' => $this->getSortedChildren($section, ForumSectionType::Subcategory),
                ]],
                'directSubcategories' => [],
            ]];
        }

        return [[
            'division' => $section,
            'categories' => array_map(
                static fn (ForumSection $cat) => [
                    'category' => $cat,
                    'subcategories' => $this->getSortedChildren($cat, ForumSectionType::Subcategory),
                ],
                $this->getSortedChildren($section, ForumSectionType::Category),
            ),
            'directSubcategories' => $this->getSortedChildren($section, ForumSectionType::Subcategory),
        ]];
    }

    /**
     * @return list<ForumSection> From the root division down to the current node
     */
    public function getAncestors(ForumSection $section): array
    {
        $this->ensureLocaleLoaded($section->getLocale());
        $chain = [];
        $parentId = $this->parentIdBySectionId[$section->getId() ?? 0] ?? null;

        while ($parentId !== null && isset($this->sectionsById[$parentId])) {
            $parent = $this->sectionsById[$parentId];
            array_unshift($chain, $parent);
            $parentId = $this->parentIdBySectionId[$parentId] ?? null;
        }

        return $chain;
    }

    /**
     * @return list<ForumSection> Including the current node
     */
    public function getBreadcrumbChain(ForumSection $section): array
    {
        return [...$this->getAncestors($section), $section];
    }

    public function validateParent(ForumSectionType $type, ?ForumSection $parent): ?string
    {
        $allowedParents = $type->allowedParentTypes();

        if ($allowedParents === [] && $parent !== null) {
            return 'Bölüm seviyesinde üst kayıt olamaz.';
        }

        if ($allowedParents !== [] && $parent === null) {
            return match ($type) {
                ForumSectionType::Category => 'Kategori için bir bölüm seçmelisiniz.',
                ForumSectionType::Subcategory => 'Alt kategori için bir kategori veya bölüm seçmelisiniz.',
                default => 'Üst kayıt zorunludur.',
            };
        }

        if ($parent !== null && !in_array($this->resolveEffectiveType($parent), $allowedParents, true)) {
            return sprintf(
                '%s için geçerli üst tip: %s',
                $type->label(),
                implode(', ', array_map(static fn (ForumSectionType $t) => $t->label(), $allowedParents)),
            );
        }

        return null;
    }

    /**
     * Infer type from structure when section_type disagrees with is_container/allow_topics/parent_id (legacy rows).
     */
    public function resolveEffectiveType(ForumSection $section): ForumSectionType
    {
        if ($section->getParent() === null && $section->isContainer()) {
            return ForumSectionType::Division;
        }

        if ($section->isContainer() && $section->getParent() !== null) {
            return ForumSectionType::Category;
        }

        if (!$section->isContainer() && $section->allowsTopics()) {
            return ForumSectionType::Subcategory;
        }

        return $section->getSectionType();
    }

    public function formatParentOptionLabel(ForumSection $section): string
    {
        $titles = array_map(
            static fn (ForumSection $s) => $s->getTitle(),
            $this->getBreadcrumbChain($section),
        );

        return sprintf(
            '%s (%s)',
            implode(' › ', $titles),
            $this->resolveEffectiveType($section)->label(),
        );
    }

    /**
     * @return list<ForumSection>
     */
    public function getValidParents(ForumSectionType $type, string $locale, ?ForumSection $exclude = null): array
    {
        $this->ensureLocaleLoaded($locale);
        $all = array_values($this->sectionsById);
        $allowed = $type->allowedParentTypes();

        $parents = array_values(array_filter($all, function (ForumSection $s) use ($allowed, $exclude): bool {
            if ($exclude !== null) {
                if ($s->getId() === $exclude->getId()) {
                    return false;
                }

                if ($this->isDescendantOf($s, $exclude)) {
                    return false;
                }
            }

            return in_array($this->resolveEffectiveType($s), $allowed, true);
        }));

        usort($parents, function (ForumSection $a, ForumSection $b): int {
            $pathA = implode("\0", array_map(static fn (ForumSection $s) => $s->getTitle(), $this->getBreadcrumbChain($a)));
            $pathB = implode("\0", array_map(static fn (ForumSection $s) => $s->getTitle(), $this->getBreadcrumbChain($b)));

            return $pathA <=> $pathB ?: $a->getSortOrder() <=> $b->getSortOrder();
        });

        return $parents;
    }

    /**
     * Admin form map: level → parent-option list.
     *
     * @return array<string, list<array{id: int, label: string}>>
     */
    public function buildParentOptionsByType(string $locale, ?ForumSection $exclude = null): array
    {
        $map = [];

        foreach (ForumSectionType::cases() as $type) {
            if ($type->allowedParentTypes() === []) {
                $map[$type->value] = [];
                continue;
            }

            $map[$type->value] = array_map(
                fn (ForumSection $s): array => [
                    'id' => (int) $s->getId(),
                    'label' => $this->formatParentOptionLabel($s),
                ],
                $this->getValidParents($type, $locale, $exclude),
            );
        }

        return $map;
    }

    private function isDescendantOf(ForumSection $node, ForumSection $ancestor): bool
    {
        $this->ensureLocaleLoaded($node->getLocale());
        $ancestorId = $ancestor->getId();
        $currentId = $this->parentIdBySectionId[$node->getId() ?? 0] ?? null;

        while ($currentId !== null && isset($this->sectionsById[$currentId])) {
            if ($currentId === $ancestorId) {
                return true;
            }

            $currentId = $this->parentIdBySectionId[$currentId] ?? null;
        }

        return false;
    }

    /**
     * @return list<array{section: ForumSection, depth: int, type: ForumSectionType}>
     */
    public function buildAdminTree(string $locale): array
    {
        $rows = [];
        foreach ($this->getDivisions($locale) as $division) {
            $this->appendAdminRow($rows, $division, 0);
        }

        return $rows;
    }

    /**
     * @param list<array{section: ForumSection, depth: int, type: ForumSectionType}> $rows
     */
    private function appendAdminRow(array &$rows, ForumSection $section, int $depth): void
    {
        $rows[] = ['section' => $section, 'depth' => $depth, 'type' => $section->getSectionType()];

        foreach ($this->getSortedChildren($section) as $child) {
            $this->appendAdminRow($rows, $child, $depth + 1);
        }
    }
}
