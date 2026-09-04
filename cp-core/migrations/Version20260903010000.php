<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * CPalius Forum Engine — düğüm bazlı rol/izin matrisi (cp_forum_node_permissions).
 */
final class Version20260903010000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Adds forum node permission matrix table for CPalius Forum Studio.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE forum_node_permissions (
            id INT AUTO_INCREMENT NOT NULL,
            section_id INT NOT NULL,
            role_key VARCHAR(32) NOT NULL,
            permission_key VARCHAR(32) NOT NULL,
            allowed TINYINT(1) NOT NULL DEFAULT 1,
            INDEX IDX_FNP_SECTION (section_id),
            UNIQUE INDEX UNIQ_FNP_SECTION_ROLE_PERM (section_id, role_key, permission_key),
            PRIMARY KEY(id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

        $this->addSql('ALTER TABLE forum_node_permissions
            ADD CONSTRAINT FK_FNP_SECTION FOREIGN KEY (section_id) REFERENCES forum_sections (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE forum_node_permissions DROP FOREIGN KEY FK_FNP_SECTION');
        $this->addSql('DROP TABLE forum_node_permissions');
    }
}
