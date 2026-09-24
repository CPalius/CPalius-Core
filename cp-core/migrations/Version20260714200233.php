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
        // Installations that already recorded the empty Version20260714194222
        // placeholder never create these tables. Build them here before altering.
        $this->addSql(<<<'SQL'
            CREATE TABLE IF NOT EXISTS categories (
                id INT AUTO_INCREMENT NOT NULL,
                name VARCHAR(255) NOT NULL,
                slug VARCHAR(255) NOT NULL,
                description LONGTEXT DEFAULT NULL,
                parent_id INT DEFAULT NULL,
                INDEX idx_category_slug (slug),
                UNIQUE INDEX UNIQ_3AF34668989D9B62 (slug),
                INDEX IDX_3AF34668727ACA70 (parent_id),
                PRIMARY KEY (id)
            ) DEFAULT CHARACTER SET utf8mb4
            SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE IF NOT EXISTS nodes (
                id INT AUTO_INCREMENT NOT NULL,
                title VARCHAR(255) NOT NULL,
                slug VARCHAR(255) NOT NULL,
                type VARCHAR(50) NOT NULL,
                status VARCHAR(20) NOT NULL,
                locale VARCHAR(5) NOT NULL,
                translation_group_id BINARY(16) NOT NULL,
                data JSON NOT NULL,
                category_id INT DEFAULT NULL,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                published_at DATETIME DEFAULT NULL,
                INDEX idx_node_type (type),
                INDEX idx_node_status (status),
                INDEX idx_node_locale (locale),
                INDEX idx_node_translation_group (translation_group_id),
                UNIQUE INDEX uniq_node_slug_locale (slug, locale),
                INDEX IDX_1D3D05FC12469DE2 (category_id),
                PRIMARY KEY (id)
            ) DEFAULT CHARACTER SET utf8mb4
            SQL);
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
