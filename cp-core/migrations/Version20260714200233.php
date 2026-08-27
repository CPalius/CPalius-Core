<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260714200233 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('DROP INDEX idx_category_slug ON categories');
        $this->addSql('DROP INDEX UNIQ_3AF34668989D9B62 ON categories');
        $this->addSql('ALTER TABLE categories ADD locale VARCHAR(5) NOT NULL');
        $this->addSql('CREATE INDEX idx_category_locale ON categories (locale)');
        $this->addSql('CREATE UNIQUE INDEX uniq_category_slug_locale ON categories (slug, locale)');
        $this->addSql('DROP INDEX idx_node_translation_group ON nodes');
        $this->addSql('ALTER TABLE nodes CHANGE translation_group_id translation_group_id BINARY(16) DEFAULT NULL');
        $this->addSql('CREATE UNIQUE INDEX uniq_node_translation_group_locale ON nodes (translation_group_id, locale)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('DROP INDEX idx_category_locale ON categories');
        $this->addSql('DROP INDEX uniq_category_slug_locale ON categories');
        $this->addSql('ALTER TABLE categories DROP locale');
        $this->addSql('CREATE INDEX idx_category_slug ON categories (slug)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_3AF34668989D9B62 ON categories (slug)');
        $this->addSql('DROP INDEX uniq_node_translation_group_locale ON nodes');
        $this->addSql('ALTER TABLE nodes CHANGE translation_group_id translation_group_id BINARY(16) NOT NULL');
        $this->addSql('CREATE INDEX idx_node_translation_group ON nodes (translation_group_id)');
    }
}
