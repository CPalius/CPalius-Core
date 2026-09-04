<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * CPalius Forum Studio: node locked/sort, prefix-section matrix.
 */
final class Version20260902233000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Adds forum node lock/sort settings and prefix-section availability matrix.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE forum_sections
            ADD is_locked TINYINT(1) NOT NULL DEFAULT 0,
            ADD default_topic_sort VARCHAR(32) NOT NULL DEFAULT 'latest'");

        $this->addSql('CREATE TABLE forum_prefix_sections (
            prefix_id INT NOT NULL,
            section_id INT NOT NULL,
            INDEX IDX_FPS_PREFIX (prefix_id),
            INDEX IDX_FPS_SECTION (section_id),
            PRIMARY KEY(prefix_id, section_id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

        $this->addSql('ALTER TABLE forum_prefix_sections
            ADD CONSTRAINT FK_FPS_PREFIX FOREIGN KEY (prefix_id) REFERENCES forum_topic_prefixes (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE forum_prefix_sections
            ADD CONSTRAINT FK_FPS_SECTION FOREIGN KEY (section_id) REFERENCES forum_sections (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE forum_prefix_sections DROP FOREIGN KEY FK_FPS_PREFIX');
        $this->addSql('ALTER TABLE forum_prefix_sections DROP FOREIGN KEY FK_FPS_SECTION');
        $this->addSql('DROP TABLE forum_prefix_sections');
        $this->addSql('ALTER TABLE forum_sections DROP is_locked, DROP default_topic_sort');
    }
}
