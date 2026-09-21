<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use Modules\Forum\ForumPermission;

/**
 * Forum Phase B: ternary ACL (effect), permission_key 64, poll→poll_create,
 * user overrides, local moderators, cache vitrine, and permission packs.
 *
 * allowed=1 → effect=allow, allowed=0 → effect=deny, then allowed is dropped.
 * Guarded so the module SQL file and this class can each arrive first.
 */
final class Version20260922010000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Forum Phase B: ternary node ACL, user overrides, local moderators, permission packs.';
    }

    public function isTransactional(): bool
    {
        return false;
    }

    public function up(Schema $schema): void
    {
        $this->alterNodePermissions();
        $this->createUserPermissions();
        $this->createModerators();
        $this->createModeratorCache();
        $this->createPermissionRoles();
        $this->seedPermissionPacks();
    }

    public function down(Schema $schema): void
    {
        foreach ([
            'cp_forum_moderator_cache',
            'cp_forum_permission_role_grants',
            'cp_forum_permission_roles',
            'cp_forum_user_permissions',
            'cp_forum_moderators',
        ] as $table) {
            if ($this->tableExists($table)) {
                $this->connection->executeStatement('DROP TABLE `'.$table.'`');
            }
        }

        if (!$this->tableExists('cp_forum_node_permissions')) {
            return;
        }

        if (!$this->columnExists('cp_forum_node_permissions', 'allowed') && $this->columnExists('cp_forum_node_permissions', 'effect')) {
            $this->connection->executeStatement(
                'ALTER TABLE cp_forum_node_permissions ADD COLUMN allowed TINYINT(1) NOT NULL DEFAULT 1',
            );
            $this->connection->executeStatement(
                "UPDATE cp_forum_node_permissions SET allowed = IF(effect = 'allow', 1, 0)",
            );
            $this->connection->executeStatement('ALTER TABLE cp_forum_node_permissions DROP COLUMN effect');
        }

        $this->connection->executeStatement(
            "UPDATE cp_forum_node_permissions SET permission_key = 'poll' WHERE permission_key = 'poll_create'",
        );
        $this->connection->executeStatement(
            'ALTER TABLE cp_forum_node_permissions MODIFY permission_key VARCHAR(32) NOT NULL',
        );
    }

    private function alterNodePermissions(): void
    {
        if (!$this->tableExists('cp_forum_node_permissions')) {
            return;
        }

        if (!$this->columnExists('cp_forum_node_permissions', 'effect')) {
            $this->connection->executeStatement(
                'ALTER TABLE cp_forum_node_permissions ADD COLUMN effect VARCHAR(8) DEFAULT NULL',
            );
        }

        if ($this->columnExists('cp_forum_node_permissions', 'allowed')) {
            $this->connection->executeStatement(
                "UPDATE cp_forum_node_permissions
                 SET effect = IF(allowed = 1, 'allow', 'deny')
                 WHERE effect IS NULL OR effect = ''",
            );
            $this->connection->executeStatement('ALTER TABLE cp_forum_node_permissions DROP COLUMN allowed');
        }

        $this->connection->executeStatement(
            "UPDATE cp_forum_node_permissions SET effect = 'inherit' WHERE effect IS NULL OR effect = ''",
        );
        $this->connection->executeStatement(
            'ALTER TABLE cp_forum_node_permissions MODIFY effect VARCHAR(8) NOT NULL',
        );

        $this->connection->executeStatement(
            "DELETE p FROM cp_forum_node_permissions p
             INNER JOIN cp_forum_node_permissions n
                ON p.section_id = n.section_id
               AND p.role_key = n.role_key
             WHERE p.permission_key = 'poll' AND n.permission_key = 'poll_create'",
        );
        $this->connection->executeStatement(
            "UPDATE cp_forum_node_permissions SET permission_key = 'poll_create' WHERE permission_key = 'poll'",
        );

        $length = (int) $this->connection->fetchOne(
            "SELECT CHARACTER_MAXIMUM_LENGTH FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'cp_forum_node_permissions'
               AND COLUMN_NAME = 'permission_key'",
        );
        if ($length > 0 && $length < 64) {
            $this->connection->executeStatement(
                'ALTER TABLE cp_forum_node_permissions MODIFY permission_key VARCHAR(64) NOT NULL',
            );
        }
    }

    private function createUserPermissions(): void
    {
        if ($this->tableExists('cp_forum_user_permissions')) {
            return;
        }

        $this->connection->executeStatement(
            'CREATE TABLE cp_forum_user_permissions (
                id INT AUTO_INCREMENT NOT NULL,
                section_id INT NOT NULL DEFAULT 0,
                user_id INT NOT NULL,
                permission_key VARCHAR(64) NOT NULL,
                effect VARCHAR(8) NOT NULL,
                UNIQUE INDEX uniq_fup_section_user_perm (section_id, user_id, permission_key),
                INDEX idx_fup_user (user_id),
                PRIMARY KEY(id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB',
        );
        $this->connection->executeStatement(
            'ALTER TABLE cp_forum_user_permissions
             ADD CONSTRAINT FK_FORUM_USER_PERM_USER FOREIGN KEY (user_id) REFERENCES cp_users (id) ON DELETE CASCADE',
        );
    }

    private function createModerators(): void
    {
        if ($this->tableExists('cp_forum_moderators')) {
            return;
        }

        $this->connection->executeStatement(
            'CREATE TABLE cp_forum_moderators (
                id INT AUTO_INCREMENT NOT NULL,
                section_id INT NOT NULL,
                subject_type VARCHAR(8) NOT NULL,
                subject_id INT NOT NULL,
                subject_key VARCHAR(32) NOT NULL DEFAULT \'\',
                inherit_children TINYINT(1) NOT NULL DEFAULT 1,
                grant_keys LONGTEXT NOT NULL COMMENT \'(DC2Type:json)\',
                created_by_id INT DEFAULT NULL,
                created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\',
                UNIQUE INDEX uniq_forum_mod_slot (section_id, subject_type, subject_id, subject_key),
                INDEX IDX_FORUM_MOD_SECTION (section_id),
                INDEX IDX_FORUM_MOD_CREATED_BY (created_by_id),
                PRIMARY KEY(id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB',
        );
        $this->connection->executeStatement(
            'ALTER TABLE cp_forum_moderators
             ADD CONSTRAINT FK_FORUM_MOD_SECTION FOREIGN KEY (section_id) REFERENCES cp_forum_sections (id) ON DELETE CASCADE',
        );
        $this->connection->executeStatement(
            'ALTER TABLE cp_forum_moderators
             ADD CONSTRAINT FK_FORUM_MOD_CREATED_BY FOREIGN KEY (created_by_id) REFERENCES cp_users (id) ON DELETE SET NULL',
        );
    }

    private function createModeratorCache(): void
    {
        if ($this->tableExists('cp_forum_moderator_cache')) {
            return;
        }

        $this->connection->executeStatement(
            'CREATE TABLE cp_forum_moderator_cache (
                id INT AUTO_INCREMENT NOT NULL,
                section_id INT NOT NULL,
                user_id INT DEFAULT NULL,
                display_name VARCHAR(100) NOT NULL,
                subject_type VARCHAR(8) NOT NULL,
                display_on_index TINYINT(1) NOT NULL DEFAULT 1,
                sort_order INT NOT NULL DEFAULT 0,
                UNIQUE INDEX uniq_fmc_section_user (section_id, user_id, subject_type, display_name),
                INDEX IDX_FORUM_MOD_CACHE_SECTION (section_id),
                INDEX IDX_FORUM_MOD_CACHE_USER (user_id),
                PRIMARY KEY(id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB',
        );
        $this->connection->executeStatement(
            'ALTER TABLE cp_forum_moderator_cache
             ADD CONSTRAINT FK_FORUM_MOD_CACHE_SECTION FOREIGN KEY (section_id) REFERENCES cp_forum_sections (id) ON DELETE CASCADE',
        );
        $this->connection->executeStatement(
            'ALTER TABLE cp_forum_moderator_cache
             ADD CONSTRAINT FK_FORUM_MOD_CACHE_USER FOREIGN KEY (user_id) REFERENCES cp_users (id) ON DELETE CASCADE',
        );
    }

    private function createPermissionRoles(): void
    {
        if (!$this->tableExists('cp_forum_permission_roles')) {
            $this->connection->executeStatement(
                'CREATE TABLE cp_forum_permission_roles (
                    id INT AUTO_INCREMENT NOT NULL,
                    code VARCHAR(32) NOT NULL,
                    label VARCHAR(64) NOT NULL,
                    scope VARCHAR(16) NOT NULL,
                    UNIQUE INDEX uniq_forum_perm_role_code (code),
                    PRIMARY KEY(id)
                ) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB',
            );
        }

        if ($this->tableExists('cp_forum_permission_role_grants')) {
            return;
        }

        $this->connection->executeStatement(
            'CREATE TABLE cp_forum_permission_role_grants (
                id INT AUTO_INCREMENT NOT NULL,
                role_id INT NOT NULL,
                permission_key VARCHAR(64) NOT NULL,
                effect VARCHAR(8) NOT NULL,
                UNIQUE INDEX uniq_forum_perm_role_grant (role_id, permission_key),
                INDEX IDX_FORUM_PERM_ROLE_GRANT_ROLE (role_id),
                PRIMARY KEY(id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB',
        );
        $this->connection->executeStatement(
            'ALTER TABLE cp_forum_permission_role_grants
             ADD CONSTRAINT FK_FORUM_PERM_ROLE_GRANT_ROLE FOREIGN KEY (role_id) REFERENCES cp_forum_permission_roles (id) ON DELETE CASCADE',
        );
    }

    private function seedPermissionPacks(): void
    {
        if (!$this->tableExists('cp_forum_permission_roles') || !$this->tableExists('cp_forum_permission_role_grants')) {
            return;
        }

        $packs = [
            ['read_only', 'Read Only', 'content', ForumPermission::readOnlyGrants()],
            ['standard_member', 'Standard Member', 'content', ForumPermission::standardMemberGrants()],
            ['standard_local_mod', 'Standard Local Mod', 'moderate', ForumPermission::standardLocalModGrants()],
        ];

        foreach ($packs as [$code, $label, $scope, $grants]) {
            $exists = (int) $this->connection->fetchOne(
                'SELECT COUNT(*) FROM cp_forum_permission_roles WHERE code = ?',
                [$code],
            );
            if ($exists === 0) {
                $this->connection->insert('cp_forum_permission_roles', [
                    'code' => $code,
                    'label' => $label,
                    'scope' => $scope,
                ]);
            }

            $roleId = (int) $this->connection->fetchOne(
                'SELECT id FROM cp_forum_permission_roles WHERE code = ?',
                [$code],
            );
            if ($roleId < 1) {
                continue;
            }

            foreach ($grants as $permission) {
                $has = (int) $this->connection->fetchOne(
                    'SELECT COUNT(*) FROM cp_forum_permission_role_grants WHERE role_id = ? AND permission_key = ?',
                    [$roleId, $permission->value],
                );
                if ($has > 0) {
                    continue;
                }

                $this->connection->insert('cp_forum_permission_role_grants', [
                    'role_id' => $roleId,
                    'permission_key' => $permission->value,
                    'effect' => 'allow',
                ]);
            }
        }
    }

    private function tableExists(string $table): bool
    {
        return (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?',
            [$table],
        ) > 0;
    }

    private function columnExists(string $table, string $column): bool
    {
        return (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            [$table, $column],
        ) > 0;
    }
}
