<?php

declare(strict_types=1);

namespace Modules\Forum\Service;

use App\Core\Security\RoleConfigManager;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Modules\Forum\Entity\ForumNodePermission;
use Modules\Forum\Entity\ForumSection;
use Modules\Forum\Entity\ForumUserPermission;
use Modules\Forum\ForumAclEffect;
use Modules\Forum\ForumPermission;
use Modules\Forum\Repository\ForumModeratorRepository;
use Modules\Forum\Repository\ForumNodePermissionRepository;
use Modules\Forum\Repository\ForumSectionRepository;
use Modules\Forum\Repository\ForumUserPermissionRepository;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Node × group permission matrix and 4-layer deny-wins resolver.
 *
 * Resolve order: user override → groups at this node (deny wins) → parent path → default.
 */
final class ForumPermissionService
{
    private const CACHE_TTL = 3600;

    /** @var array<string, array<string, ForumAclEffect>> locale => sectionId:role:perm => effect */
    private array $runtime = [];

    /** @var array<int, array<int, array<string, ForumAclEffect>>> userId => sectionId => perm => effect */
    private array $userOverrideRuntime = [];

    /** @var array<int, list<\Modules\Forum\Entity\ForumModerator>> */
    private array $moderatorRuntime = [];

    public function __construct(
        private readonly ForumNodePermissionRepository $permissionRepository,
        private readonly ForumSectionRepository $sectionRepository,
        private readonly ForumUserPermissionRepository $userPermissionRepository,
        private readonly ForumModeratorRepository $moderatorRepository,
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
     * @return list<string>
     */
    public function matrixPermissionKeys(): array
    {
        return array_map(static fn (ForumPermission $p): string => $p->value, ForumPermission::cases());
    }

    /**
     * @return list<string>
     */
    public function matrixContentKeys(): array
    {
        return array_map(static fn (ForumPermission $p): string => $p->value, ForumPermission::contentCases());
    }

    /**
     * @return list<string>
     */
    public function matrixModerateKeys(): array
    {
        return array_map(static fn (ForumPermission $p): string => $p->value, ForumPermission::moderateCases());
    }

    /**
     * @return array<string, array<string, string>> role => perm => inherit|allow|deny
     */
    public function buildSectionMatrix(ForumSection $section): array
    {
        $indexed = [];
        foreach ($this->permissionRepository->findForSection($section) as $row) {
            if ($row->getEffect() === ForumAclEffect::Inherit) {
                continue;
            }
            $indexed[$row->getRoleKey()][$row->getPermissionKey()] = $row->getEffect()->value;
        }

        $cells = [];
        foreach ($this->matrixRoleIds() as $role) {
            foreach ($this->matrixPermissionKeys() as $perm) {
                $cells[$role][$perm] = $indexed[$role][$perm] ?? ForumAclEffect::Inherit->value;
            }
        }

        return $cells;
    }

    /**
     * Sparse persist: inherit is the absence of a row.
     *
     * @param array<string, array<string, string>> $posted role => perm => inherit|allow|deny
     */
    public function persistSectionMatrix(ForumSection $section, array $posted): void
    {
        $this->permissionRepository->deleteForSection($section);

        foreach ($this->matrixRoleIds() as $role) {
            foreach ($this->matrixPermissionKeys() as $perm) {
                $effect = ForumAclEffect::tryFrom((string) ($posted[$role][$perm] ?? '')) ?? ForumAclEffect::Inherit;
                if ($effect === ForumAclEffect::Inherit) {
                    continue;
                }
                $this->entityManager->persist(new ForumNodePermission($section, $role, $perm, $effect));
            }
        }

        $this->entityManager->flush();
        $this->invalidateCache($section->getLocale());
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
            if ($sectionId === null || $row->getEffect() === ForumAclEffect::Inherit) {
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
     *
     * @deprecated use persistSectionMatrix (sparse ternary)
     */
    public function persistMatrix(array $posted, string $locale = 'tr'): void
    {
        $sections = $this->sectionRepository->findAllByLocale($locale);

        foreach ($sections as $section) {
            $sectionId = $section->getId();
            if ($sectionId === null) {
                continue;
            }

            $cells = [];
            foreach ($this->matrixRoleIds() as $role) {
                foreach ($this->matrixPermissionKeys() as $perm) {
                    if (!isset($posted[$sectionId][$role][$perm])) {
                        $cells[$role][$perm] = ForumAclEffect::Inherit->value;
                        continue;
                    }
                    $cells[$role][$perm] = $posted[$sectionId][$role][$perm]
                        ? ForumAclEffect::Allow->value
                        : ForumAclEffect::Deny->value;
                }
            }
            $this->persistSectionMatrix($section, $cells);
        }
    }

    public function copyToSection(ForumSection $source, ForumSection $target): void
    {
        if ($source->getId() === $target->getId()) {
            return;
        }

        foreach ($this->permissionRepository->findForSection($source) as $row) {
            if ($row->getEffect() === ForumAclEffect::Inherit) {
                continue;
            }
            $this->entityManager->persist(new ForumNodePermission(
                $target,
                $row->getRoleKey(),
                $row->getPermissionKey(),
                $row->getEffect(),
            ));
        }

        $this->invalidateCache($source->getLocale());
    }

    public function isAllowed(ForumSection $section, ?User $user, string $permission): bool
    {
        $permission = $this->normalizePermission($permission);

        if ($this->bypassesContentAcl($user)) {
            return true;
        }

        $chain = $this->sectionChain($section);

        if ($user !== null) {
            $overrides = $this->userOverrides($user);
            foreach ($chain as $node) {
                $sid = $node->getId();
                if ($sid === null) {
                    continue;
                }
                $effect = $overrides[$sid][$permission] ?? null;
                if ($effect === ForumAclEffect::Deny) {
                    return false;
                }
                if ($effect === ForumAclEffect::Allow) {
                    return true;
                }
            }

            $global = $overrides[ForumUserPermission::GLOBAL_SECTION_ID][$permission] ?? null;
            if ($global === ForumAclEffect::Deny) {
                return false;
            }
            if ($global === ForumAclEffect::Allow) {
                return true;
            }
        }

        $roles = $this->roleKeysForUser($user);
        foreach ($chain as $node) {
            $deny = false;
            $allow = false;
            foreach ($roles as $role) {
                $effect = $this->lookup($node, $role, $permission);
                if ($effect === ForumAclEffect::Deny) {
                    $deny = true;
                } elseif ($effect === ForumAclEffect::Allow) {
                    $allow = true;
                }
            }
            if ($deny) {
                return false;
            }
            if ($allow) {
                return true;
            }
        }

        $deny = false;
        $allow = false;
        foreach ($roles as $role) {
            if ($this->defaultAllowed($role, $permission)) {
                $allow = true;
            } else {
                $deny = true;
            }
        }

        return $allow && !$deny;
    }

    public function isModeratorAllowed(ForumSection $section, ?User $user, string $permission): bool
    {
        if ($user === null) {
            return false;
        }

        $permission = $this->normalizeModeratePermission($permission);
        $wanted = ForumPermission::tryFrom($permission);
        if ($wanted === null || !$wanted->isModerate()) {
            return false;
        }

        if ($this->bypassesContentAcl($user)) {
            return true;
        }

        $leafId = $section->getId();
        $ancestorIds = $this->ancestorIdSet($section);

        foreach ($this->moderatorAssignments($user) as $assignment) {
            $assignedId = $assignment->getSection()->getId();
            if ($assignedId === null) {
                continue;
            }

            $applies = $assignedId === $leafId
                || ($assignment->inheritsChildren() && isset($ancestorIds[$assignedId]));
            if (!$applies) {
                continue;
            }

            if (\in_array($permission, $assignment->resolvedGrantKeys(), true)) {
                return true;
            }
        }

        return false;
    }

    public function bypassesContentAcl(?User $user): bool
    {
        if ($user === null) {
            return false;
        }

        return $this->authorizationChecker->isGranted('forum.section.manage')
            || $this->authorizationChecker->isGranted('forum.nodes.manage')
            || $this->authorizationChecker->isGranted('forum.topic.moderate');
    }

    private function lookup(ForumSection $section, string $role, string $permission): ?ForumAclEffect
    {
        $sectionId = $section->getId();
        if ($sectionId === null) {
            return null;
        }

        return $this->matrixForLocale($section->getLocale())[$sectionId.':'.$role.':'.$permission] ?? null;
    }

    /** @return array<string, ForumAclEffect> */
    private function matrixForLocale(string $locale): array
    {
        if (isset($this->runtime[$locale])) {
            return $this->runtime[$locale];
        }

        try {
            /** @var array<string, string> $raw */
            $raw = $this->cache->get($this->cacheKey($locale), function (ItemInterface $item) use ($locale): array {
                $item->expiresAfter(self::CACHE_TTL);

                return $this->serializeMatrix($this->loadMatrixFromDb($locale));
            });
            $map = $this->hydrateMatrix($raw);
        } catch (\Throwable) {
            $map = $this->loadMatrixFromDb($locale);
        }

        return $this->runtime[$locale] = $map;
    }

    /** @return array<string, ForumAclEffect> */
    private function loadMatrixFromDb(string $locale): array
    {
        $map = [];
        foreach ($this->permissionRepository->findAllForLocale($locale) as $row) {
            $sectionId = $row->getSection()->getId();
            $effect = $row->getEffect();
            if ($sectionId === null || $effect === ForumAclEffect::Inherit) {
                continue;
            }
            $map[$sectionId.':'.$row->getRoleKey().':'.$row->getPermissionKey()] = $effect;
        }

        return $map;
    }

    /**
     * @param array<string, ForumAclEffect> $map
     *
     * @return array<string, string>
     */
    private function serializeMatrix(array $map): array
    {
        $out = [];
        foreach ($map as $key => $effect) {
            $out[$key] = $effect->value;
        }

        return $out;
    }

    /**
     * @param array<string, string> $raw
     *
     * @return array<string, ForumAclEffect>
     */
    private function hydrateMatrix(array $raw): array
    {
        $map = [];
        foreach ($raw as $key => $value) {
            $effect = ForumAclEffect::tryFrom($value);
            if ($effect instanceof ForumAclEffect && $effect !== ForumAclEffect::Inherit) {
                $map[$key] = $effect;
            }
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
     * @return array<int, array<string, ForumAclEffect>>
     */
    private function userOverrides(User $user): array
    {
        $userId = $user->getId() ?? 0;
        if (isset($this->userOverrideRuntime[$userId])) {
            return $this->userOverrideRuntime[$userId];
        }

        $map = [];
        foreach ($this->userPermissionRepository->findForUser($user) as $row) {
            $effect = $row->getEffect();
            if ($effect === ForumAclEffect::Inherit) {
                continue;
            }
            $map[$row->getSectionId()][$row->getPermissionKey()] = $effect;
        }

        return $this->userOverrideRuntime[$userId] = $map;
    }

    /**
     * @return list<\Modules\Forum\Entity\ForumModerator>
     */
    private function moderatorAssignments(User $user): array
    {
        $userId = $user->getId() ?? 0;
        if (isset($this->moderatorRuntime[$userId])) {
            return $this->moderatorRuntime[$userId];
        }

        return $this->moderatorRuntime[$userId] = $this->moderatorRepository->findMatching(
            $user,
            $this->roleKeysForUser($user),
        );
    }

    /**
     * Leaf-first ancestor chain.
     *
     * @return list<ForumSection>
     */
    private function sectionChain(ForumSection $section): array
    {
        $chain = [$section];
        $cursor = $section->getParent();
        $guard = 0;
        $seen = [$section->getId() ?? 0 => true];

        while ($cursor instanceof ForumSection && $guard++ < 32) {
            $id = $cursor->getId() ?? 0;
            if ($id > 0 && isset($seen[$id])) {
                break;
            }
            $seen[$id] = true;
            $chain[] = $cursor;
            $cursor = $cursor->getParent();
        }

        if (\count($chain) === 1) {
            $self = $section->getId();
            $extra = array_values(array_filter(
                $section->ancestorIds(),
                static fn (int $id): bool => $id > 0 && $id !== $self,
            ));
            if ($extra !== []) {
                $found = $this->sectionRepository->findBy(['id' => $extra]);
                $byId = [];
                foreach ($found as $row) {
                    $byId[$row->getId() ?? 0] = $row;
                }
                foreach (array_reverse($extra) as $id) {
                    if (isset($byId[$id])) {
                        $chain[] = $byId[$id];
                    }
                }
            }
        }

        return $chain;
    }

    /**
     * @return array<int, true>
     */
    private function ancestorIdSet(ForumSection $section): array
    {
        $set = [];
        foreach ($this->sectionChain($section) as $node) {
            $id = $node->getId();
            if ($id !== null) {
                $set[$id] = true;
            }
        }

        return $set;
    }

    /**
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

        return array_values(array_unique($keys));
    }

    private function defaultAllowed(string $role, string $permission): bool
    {
        $permission = $this->normalizePermission($permission);

        return match ($role) {
            ForumNodePermission::ROLE_GUEST => $permission === ForumPermission::View->value,
            ForumNodePermission::ROLE_ADMIN, ForumNodePermission::ROLE_MODERATOR => ForumPermission::tryFrom($permission)?->isContent() ?? false,
            'read_only' => \in_array($permission, $this->packKeys(ForumPermission::readOnlyGrants()), true),
            default => \in_array($permission, $this->packKeys(ForumPermission::standardMemberGrants()), true),
        };
    }

    /**
     * @param list<ForumPermission> $pack
     *
     * @return list<string>
     */
    private function packKeys(array $pack): array
    {
        return array_map(static fn (ForumPermission $p): string => $p->value, $pack);
    }

    private function normalizePermission(string $permission): string
    {
        return $permission === 'poll' ? ForumPermission::PollCreate->value : $permission;
    }

    private function normalizeModeratePermission(string $permission): string
    {
        if (str_starts_with($permission, 'moderate.')) {
            return $permission;
        }

        return 'moderate.'.$permission;
    }
}
