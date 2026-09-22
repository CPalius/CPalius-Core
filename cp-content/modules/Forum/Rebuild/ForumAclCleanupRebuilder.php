<?php

declare(strict_types=1);

namespace Modules\Forum\Rebuild;

use App\Core\Localization\LocaleProvider;
use App\Core\Rebuild\RebuilderInterface;
use Doctrine\ORM\EntityManagerInterface;
use Modules\Forum\Entity\ForumNodePermission;
use Modules\Forum\ForumAclEffect;
use Modules\Forum\ForumPermission;
use Modules\Forum\Service\ForumPermissionService;

/**
 * Sparse-persist repair: inherit rows, unknown roles/keys, and user overrides
 * pointing at a section that no longer exists.
 */
final class ForumAclCleanupRebuilder implements RebuilderInterface
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ForumPermissionService $permissionService,
        private readonly LocaleProvider $localeProvider,
    ) {
    }

    public function getId(): string
    {
        return 'forum.acl';
    }

    public function getName(): string
    {
        return 'forum.rebuild.acl';
    }

    public function getDescription(): string
    {
        return 'forum.rebuild.acl_desc';
    }

    public function getBatchSize(): int
    {
        return 500;
    }

    public function getPriority(): int
    {
        return 10;
    }

    public function getTotal(): int
    {
        return $this->dirtyCount();
    }

    public function isStudioVisible(): bool
    {
        return true;
    }

    public function rebuild(int $offset, int $limit): int
    {
        unset($offset);
        $budget = max(1, $limit);
        $removed = 0;
        $conn = $this->entityManager->getConnection();

        $removed += $this->deleteLimited(
            "DELETE FROM cp_forum_node_permissions WHERE effect = :inherit LIMIT {$budget}",
            ['inherit' => ForumAclEffect::Inherit->value],
            $budget,
        );

        $roles = ForumNodePermission::ROLES;
        $roleList = implode(',', array_map(static fn (string $r): string => $conn->quote($r), $roles));
        if ($budget - $removed > 0) {
            $left = $budget - $removed;
            $removed += $this->deleteLimited(
                "DELETE FROM cp_forum_node_permissions WHERE role_key NOT IN ({$roleList}) LIMIT {$left}",
                [],
                $left,
            );
        }

        $permList = implode(',', array_map(
            static fn (ForumPermission $p): string => $conn->quote($p->value),
            ForumPermission::cases(),
        ));
        if ($budget - $removed > 0) {
            $left = $budget - $removed;
            $removed += $this->deleteLimited(
                "DELETE FROM cp_forum_node_permissions WHERE permission_key NOT IN ({$permList}) LIMIT {$left}",
                [],
                $left,
            );
        }

        if ($budget - $removed > 0) {
            $left = $budget - $removed;
            $removed += $this->deleteLimited(
                "DELETE FROM cp_forum_user_permissions WHERE effect = :inherit LIMIT {$left}",
                ['inherit' => ForumAclEffect::Inherit->value],
                $left,
            );
        }

        if ($budget - $removed > 0) {
            $left = $budget - $removed;
            $removed += $this->deleteLimited(
                "DELETE FROM cp_forum_user_permissions
                 WHERE section_id > 0
                   AND section_id NOT IN (SELECT id FROM cp_forum_sections)
                 LIMIT {$left}",
                [],
                $left,
            );
        }

        if ($budget - $removed > 0) {
            $left = $budget - $removed;
            $removed += $this->deleteLimited(
                "DELETE FROM cp_forum_permission_role_grants WHERE effect = :inherit LIMIT {$left}",
                ['inherit' => ForumAclEffect::Inherit->value],
                $left,
            );
        }

        if ($this->dirtyCount() === 0) {
            $this->permissionService->flushPermissionCaches($this->localeProvider->getCodes());
        }

        $this->entityManager->clear();

        return $removed === 0 ? 0 : min($budget, $removed);
    }

    private function dirtyCount(): int
    {
        $conn = $this->entityManager->getConnection();
        $roles = implode(',', array_map(
            static fn (string $r): string => $conn->quote($r),
            ForumNodePermission::ROLES,
        ));
        $perms = implode(',', array_map(
            static fn (ForumPermission $p): string => $conn->quote($p->value),
            ForumPermission::cases(),
        ));
        $inherit = ForumAclEffect::Inherit->value;

        return (int) $conn->fetchOne(
            "SELECT
                (SELECT COUNT(*) FROM cp_forum_node_permissions WHERE effect = {$conn->quote($inherit)})
              + (SELECT COUNT(*) FROM cp_forum_node_permissions WHERE role_key NOT IN ({$roles}))
              + (SELECT COUNT(*) FROM cp_forum_node_permissions WHERE permission_key NOT IN ({$perms}))
              + (SELECT COUNT(*) FROM cp_forum_user_permissions WHERE effect = {$conn->quote($inherit)})
              + (SELECT COUNT(*) FROM cp_forum_user_permissions
                 WHERE section_id > 0 AND section_id NOT IN (SELECT id FROM cp_forum_sections))
              + (SELECT COUNT(*) FROM cp_forum_permission_role_grants WHERE effect = {$conn->quote($inherit)})",
        );
    }

    /**
     * @param array<string, mixed> $params
     */
    private function deleteLimited(string $sql, array $params, int $limit): int
    {
        if ($limit < 1) {
            return 0;
        }

        return (int) $this->entityManager->getConnection()->executeStatement($sql, $params);
    }
}
