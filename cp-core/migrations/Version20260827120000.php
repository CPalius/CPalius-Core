<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Forum section types: division → category → subcategory.
 */
final class Version20260827120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Adds section_type to forum_sections and updates existing rows by hierarchy.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE forum_sections ADD section_type VARCHAR(32) NOT NULL DEFAULT 'subcategory'");

        // Root containers → division
        $this->addSql("UPDATE forum_sections SET section_type = 'division' WHERE parent_id IS NULL AND is_container = 1");

        // Child containers → category
        $this->addSql("UPDATE forum_sections SET section_type = 'category' WHERE parent_id IS NOT NULL AND is_container = 1");

        // Leaf boards → subcategory
        $this->addSql("UPDATE forum_sections SET section_type = 'subcategory' WHERE is_container = 0 AND allow_topics = 1");
    }

    public function down(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            UPDATE forum_sections child
            INNER JOIN forum_sections akademik ON akademik.code = 'akademik'
            INNER JOIN forum_sections parent ON parent.code = 'pub'
            SET child.parent_id = parent.id
            WHERE child.parent_id = akademik.id
            SQL);

        $this->addSql("DELETE FROM forum_sections WHERE code = 'akademik'");
        $this->addSql('ALTER TABLE forum_sections DROP section_type');
    }
}
