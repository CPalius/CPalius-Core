<?php

declare(strict_types=1);

namespace Modules\Forum\Service;

use Modules\Forum\Entity\ForumNodePermission;
use Modules\Forum\Entity\ForumSection;
use App\Entity\User;
use Modules\Forum\Repository\ForumNodePermissionRepository;
use Modules\Forum\Repository\ForumSectionRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

/**
 * Node permission matrix: persist, read, and runtime checks.
 */
final class ForumPermissionService
{
    /** @var array<string, bool>|null sectionId:role:perm => allowed */
    private ?array $matrixCache = null;

    public function __construct(
        private readonly ForumNodePermissionRepository $permissionRepository,
        private readonly ForumSectionRepository $sectionRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly AuthorizationCheckerInterface $authorizationChecker,
    ) {
    }

    /**
     * @return list<array{section: ForumSection, depth: int, cells: array<string, array<string, bool>>}>
     */
    public function buildAdminMatrix(string $locale = 'tr'): array
    {
        $sections = $this->sectionRepository->findAllByLocale($locale);
        $rows = $this->permissionRepository->findAllForLocale($locale);
        $indexed = [];

        foreach ($rows as $row) {
            $sectionId = $row->getSection()->getId();
            if ($sectionId === null) {
                continue;
            }
            $indexed[$sectionId][$row->getRoleKey()][$row->getPermissionKey()] = $row->isAllowed();
        }

        $byParent = [];
        foreach ($sections as $section) {
            $parentId = $section->getParent()?->getId() ?? 0;
            $byParent[$parentId][] = $section;
        }

        foreach ($byParent as &$children) {
            usort($children, static fn (ForumSection $a, ForumSection $b): int => $a->getSortOrder() <=> $b->getSortOrder());
        }
        unset($children);

        $out = [];
        $walk = function (ForumSection $section, int $depth) use (&$walk, &$out, $byParent, $indexed): void {
            $cells = [];
            foreach (ForumNodePermission::ROLES as $role) {
                foreach (ForumNodePermission::PERMISSIONS as $perm) {
                    $cells[$role][$perm] = $indexed[$section->getId()][$role][$perm]
                        ?? $this->defaultAllowed($role, $perm);
                }
            }
            $out[] = ['section' => $section, 'depth' => $depth, 'cells' => $cells];
            foreach ($byParent[$section->getId() ?? 0] ?? [] as $child) {
                $walk($child, $depth + 1);
            }
        };

        foreach ($byParent[0] ?? [] as $root) {
            $walk($root, 0);
        }

        return $out;
    }

    /**
     * Admin matrix grouped by depth-0 root nodes.
     *
     * @return list<array{title: string, section: ForumSection, rows: list<array{section: ForumSection, depth: int, cells: array<string, array<string, bool>>}>}>
     */
    public function buildAdminMatrixGrouped(string $locale = 'tr'): array
    {
        $matrix = $this->buildAdminMatrix($locale);
        $groups = [];
        $currentIndex = -1;

        foreach ($matrix as $row) {
            if ($row['depth'] === 0) {
                $groups[] = [
                    'title' => $row['section']->getTitle(),
                    'section' => $row['section'],
                    'rows' => [$row],
                ];
                $currentIndex = \count($groups) - 1;

                continue;
            }

            if ($currentIndex >= 0) {
                $groups[$currentIndex]['rows'][] = $row;
            } else {
                $groups[] = [
                    'title' => $row['section']->getTitle(),
                    'section' => $row['section'],
                    'rows' => [$row],
                ];
                $currentIndex = \count($groups) - 1;
            }
        }

        return $groups;
    }

    /**
     * @param array<int, array<string, array<string, bool>>> $posted sectionId => role => perm => bool
     */
    public function persistMatrix(array $posted, string $locale = 'tr'): void
    {
        $sections = $this->sectionRepository->findAllByLocale($locale);

        foreach ($sections as $section) {
            $sectionId = $section->getId();
            if ($sectionId === null) {
                continue;
            }

            $this->permissionRepository->deleteForSection($section);
            $sectionCells = $posted[$sectionId] ?? [];

            foreach (ForumNodePermission::ROLES as $role) {
                foreach (ForumNodePermission::PERMISSIONS as $perm) {
                    $allowed = (bool) ($sectionCells[$role][$perm] ?? $this->defaultAllowed($role, $perm));
                    $this->entityManager->persist(new ForumNodePermission($section, $role, $perm, $allowed));
                }
            }
        }

        $this->entityManager->flush();
        $this->matrixCache = null;
    }

    public function isAllowed(ForumSection $section, ?User $user, string $permission): bool
    {
        if ($user !== null && $this->authorizationChecker->isGranted('forum.topic.moderate')) {
            return true;
        }

        $role = $this->resolveRoleKey($user);
        $cursor = $section;

        while ($cursor instanceof ForumSection) {
            $allowed = $this->lookup($cursor, $role, $permission);
            if ($allowed !== null) {
                return $allowed;
            }
            $cursor = $cursor->getParent();
        }

        return $this->defaultAllowed($role, $permission);
    }

    private function lookup(ForumSection $section, string $role, string $permission): ?bool
    {
        $sectionId = $section->getId();
        if ($sectionId === null) {
            return null;
        }

        $key = $sectionId . ':' . $role . ':' . $permission;
        if ($this->matrixCache === null) {
            $this->warmCache($section->getLocale());
        }

        return $this->matrixCache[$key] ?? null;
    }

    private function warmCache(string $locale): void
    {
        $this->matrixCache = [];
        foreach ($this->permissionRepository->findAllForLocale($locale) as $row) {
            $sectionId = $row->getSection()->getId();
            if ($sectionId === null) {
                continue;
            }
            $this->matrixCache[$sectionId . ':' . $row->getRoleKey() . ':' . $row->getPermissionKey()] = $row->isAllowed();
        }
    }

    private function resolveRoleKey(?User $user): string
    {
        if ($user === null) {
            return ForumNodePermission::ROLE_GUEST;
        }

        if ($this->authorizationChecker->isGranted('forum.section.manage')
            || $this->authorizationChecker->isGranted('forum.nodes.manage')) {
            return ForumNodePermission::ROLE_ADMIN;
        }

        if ($this->authorizationChecker->isGranted('forum.topic.moderate')) {
            return ForumNodePermission::ROLE_MODERATOR;
        }

        return ForumNodePermission::ROLE_MEMBER;
    }

    private function defaultAllowed(string $role, string $permission): bool
    {
        return match ($role) {
            ForumNodePermission::ROLE_GUEST => $permission === ForumNodePermission::PERM_VIEW,
            ForumNodePermission::ROLE_MEMBER => \in_array($permission, [
                ForumNodePermission::PERM_VIEW,
                ForumNodePermission::PERM_THREAD_CREATE,
                ForumNodePermission::PERM_REPLY,
                ForumNodePermission::PERM_UPLOAD,
            ], true),
            ForumNodePermission::ROLE_MODERATOR, ForumNodePermission::ROLE_ADMIN => true,
            default => false,
        };
    }
}
