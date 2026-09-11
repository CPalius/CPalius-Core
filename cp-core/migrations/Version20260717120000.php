<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Creates url_aliases for optional manual paths alongside canonical slug+locale URLs.
 */
final class Version20260717120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Creates the url_aliases table (additional custom URL definitions for Node/Category).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE url_aliases (
                id INT AUTO_INCREMENT NOT NULL,
                alias_path VARCHAR(255) NOT NULL,
                locale VARCHAR(5) NOT NULL,
                target_type VARCHAR(20) NOT NULL,
                target_node_id INT DEFAULT NULL,
                target_category_id INT DEFAULT NULL,
                target_route_name VARCHAR(100) DEFAULT NULL,
                is_active TINYINT(1) NOT NULL,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                UNIQUE INDEX uniq_url_alias_path_locale (alias_path, locale),
                INDEX idx_url_alias_active (is_active),
                PRIMARY KEY(id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE url_aliases');
    }
}
