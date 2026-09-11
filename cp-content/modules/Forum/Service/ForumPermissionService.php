<?php

declare(strict_types=1);

namespace Modules\Forum\Service;

use App\Core\Security\RoleConfigManager;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Modules\Forum\Entity\ForumNodePermission;
use Modules\Forum\Entity\ForumSection;
use Modules\Forum\Repository\ForumNodePermissionRepository;
use Modules\Forum\Repository\ForumSectionRepository;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Node × group permission matrix: persist, Symfony Cache, runtime checks.
 */
final class ForumPermissionService
{
    private const CACHE_TTL = 3600;

    /** @var array<string, array<string, bool>> locale => sectionId:role:perm => allowed */
    private array $runtime = [];

    public function __construct(
        private readonly ForumNodePermissionRepository $permissionRepository,
        private readonly ForumSectionRepository $sectionRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly AuthorizationCheckerInterface $authorizationChecker,
        private readonly RoleConfigManager $roleConfigManager,
        #[Autowire(service: 'cache.app')]
        private readonly CacheInterface $cache,
        private readonly TranslatorInterface $translator,
    ) {
    }

    /**
     * Groups shown in the Studio matrix: guest + YAML roles + synthetic moderator.
     *
     * @return list<array{id: string, label: string}>
     */
    public function matrixRoles(): array
    {
        $ids = [ForumNodePermission::ROLE_GUEST];
        foreach ($this->roleConfigManager->getAllRoleIds() as $id) {
            $ids[] = $id;
        }
        if (!\in_array(ForumNodePermission::ROLE_MODERATOR, $ids, true)) {
            $ids[] = ForumNodePermission::ROLE_MODERATOR;
        }

        $ids = array_values(array_unique($ids));
        $order = [
            ForumNodePermission::ROLE_GUEST => 0,
            ForumNodePermission::ROLE_MEMBER => 1,
            'editor' => 2,
            ForumNodePermission::ROLE_MODERATOR => 3,
            ForumNodePermission::ROLE_ADMIN => 4,
        ];
        usort($ids, static fn (string $a, string $b): int => ($order[$a] ?? 50) <=> ($order[$b] ?? 50) ?: $a <=> $b);

        $out = [];
        foreach ($ids as $id) {
            $out[] = [
                'id' => $id,
                'label' => $this->translator->trans($this->roleConfigManager->getLabel($id) ?? $id),
            ];
        }

        return $out;
    }

    /** @return list<string> */
    public function matrixRoleIds(): array
    {
        return array_map(static fn (array $role): string => $role['id'], $this->matrixRoles());
    }

    public function isMatrixRole(string $role): bool
    {
        return \in_array($role, $this->matrixRoleIds(), true);
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

        $roles = $this->matrixRoleIds();
        $out = [];
        $walk = function (ForumSection $section, int $depth) use (&$walk, &$out, $byParent, $indexed, $roles): void {
            $cells = [];
            foreach ($roles as $role) {
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
        $roles = $this->matrixRoleIds();

        foreach ($sections as $section) {
            $sectionId = $section->getId();
            if ($sectionId === null) {
                continue;
            }

            $this->permissionRepository->deleteForSection($section);
            $sectionCells = $posted[$sectionId] ?? [];

            foreach ($roles as $role) {
                foreach (ForumNodePermission::PERMISSIONS as $perm) {
                    $allowed = (bool) ($sectionCells[$role][$perm] ?? $this->defaultAllowed($role, $perm));
                    $this->entityManager->persist(new ForumNodePermission($section, $role, $perm, $allowed));
                }
            }
        }

        $this->entityManager->flush();
        $this->invalidateCache($locale);
    }

    public function copyToSection(ForumSection $source, ForumSection $target): void
    {
        if ($source->getId() === $target->getId()) {
            return;
        }

        foreach ($this->permissionRepository->findForSection($source) as $row) {
            $this->entityManager->persist(new ForumNodePermission(
                $target,
                $row->getRoleKey(),
                $row->getPermissionKey(),
                $row->isAllowed(),
            ));
        }

        $this->invalidateCache($source->getLocale());
    }

    public function isAllowed(ForumSection $section, ?User $user, string $permission): bool
    {
        if ($user !== null && (
            $this->authorizationChecker->isGranted('forum.section.manage')
            || $this->authorizationChecker->isGranted('forum.nodes.manage')
        )) {
            return true;
        }

        foreach ($this->roleKeysForUser($user) as $role) {
            if ($this->isAllowedForRole($section, $role, $permission)) {
                return true;
            }
        }

        return false;
    }

    private function isAllowedForRole(ForumSection $section, string $role, string $permission): bool
    {
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

        $map = $this->matrixForLocale($section->getLocale());
        $key = $sectionId.':'.$role.':'.$permission;

        return $map[$key] ?? null;
    }

    /** @return array<string, bool> */
    private function matrixForLocale(string $locale): array
    {
        if (isset($this->runtime[$locale])) {
            return $this->runtime[$locale];
        }

        try {
            /** @var array<string, bool> $map */
            $map = $this->cache->get($this->cacheKey($locale), function (ItemInterface $item) use ($locale): array {
                $item->expiresAfter(self::CACHE_TTL);

                return $this->loadMatrixFromDb($locale);
            });
        } catch (\Throwable) {
            $map = $this->loadMatrixFromDb($locale);
        }

        return $this->runtime[$locale] = $map;
    }

    /** @return array<string, bool> */
    private function loadMatrixFromDb(string $locale): array
    {
        $map = [];
        foreach ($this->permissionRepository->findAllForLocale($locale) as $row) {
            $sectionId = $row->getSection()->getId();
            if ($sectionId === null) {
                continue;
            }
            $map[$sectionId.':'.$row->getRoleKey().':'.$row->getPermissionKey()] = $row->isAllowed();
        }

        return $map;
    }

    private function invalidateCache(string $locale): void
    {
        unset($this->runtime[$locale]);
        try {
            $this->cache->delete($this->cacheKey($locale));
        } catch (\Throwable) {
        }
    }

    private function cacheKey(string $locale): string
    {
        return 'forum.node_permissions.'.$locale;
    }

    /**
     * All groups the user belongs to. Access is granted if any group allows (most permissive).
     *
     * @return list<string>
     */
    private function roleKeysForUser(?User $user): array
    {
        if ($user === null) {
            return [ForumNodePermission::ROLE_GUEST];
        }

        $keys = $user->getCpaliusRoles();
        if ($keys === []) {
            $keys = [ForumNodePermission::ROLE_MEMBER];
        }

        if ($this->authorizationChecker->isGranted('forum.topic.moderate')
            && !\in_array(ForumNodePermission::ROLE_MODERATOR, $keys, true)
        ) {
            $keys[] = ForumNodePermission::ROLE_MODERATOR;
        }

        return array_values(array_unique($keys));
    }

    private function defaultAllowed(string $role, string $permission): bool
    {
        return match ($role) {
            ForumNodePermission::ROLE_GUEST => $permission === ForumNodePermission::PERM_VIEW,
            ForumNodePermission::ROLE_ADMIN, ForumNodePermission::ROLE_MODERATOR => true,
            default => \in_array($permission, [
                ForumNodePermission::PERM_VIEW,
                ForumNodePermission::PERM_THREAD_CREATE,
                ForumNodePermission::PERM_REPLY,
                ForumNodePermission::PERM_UPLOAD,
                ForumNodePermission::PERM_POLL,
            ], true),
        };
    }
}
