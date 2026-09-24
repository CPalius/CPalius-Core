<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * The original file was lost and replaced by an empty placeholder. A database
 * that already applied that placeholder does not run this again; Version20260714200233
 * creates the same tables when they are missing. A brand-new database runs this.
 *
 * Shape is the schema Version20260714200233 alters: categories have no locale yet,
 * nodes.translation_group_id is still NOT NULL, and author/deleted_at do not exist.
 */
final class Version20260714194222 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Initial creation of categories and nodes tables.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE categories (
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
        $this->addSql('ALTER TABLE categories ADD CONSTRAINT FK_3AF34668727ACA70 FOREIGN KEY (parent_id) REFERENCES categories (id) ON DELETE SET NULL');
        $this->addSql(<<<'SQL'
            CREATE TABLE nodes (
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
        $this->addSql('ALTER TABLE nodes ADD CONSTRAINT FK_1D3D05FC12469DE2 FOREIGN KEY (category_id) REFERENCES categories (id) ON DELETE SET NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE nodes DROP FOREIGN KEY FK_1D3D05FC12469DE2');
        $this->addSql('DROP TABLE nodes');
        $this->addSql('ALTER TABLE categories DROP FOREIGN KEY FK_3AF34668727ACA70');
        $this->addSql('DROP TABLE categories');
    }
}
