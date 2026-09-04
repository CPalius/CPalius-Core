<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Roadmap modülü: native kayıt tablosu + menü #roadmap → /tr/roadmap.
 */
final class Version20260902180000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Creates roadmap_entries table and updates menu links to /tr/roadmap.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE roadmap_entries (
            id INT AUTO_INCREMENT NOT NULL,
            title VARCHAR(255) NOT NULL,
            slug VARCHAR(190) NOT NULL,
            locale VARCHAR(5) NOT NULL,
            summary LONGTEXT NOT NULL,
            body LONGTEXT DEFAULT NULL,
            status VARCHAR(32) NOT NULL,
            kind VARCHAR(32) NOT NULL,
            version_label VARCHAR(64) DEFAULT NULL,
            icon VARCHAR(64) DEFAULT NULL,
            published_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\',
            sort_order INT NOT NULL,
            is_featured TINYINT(1) NOT NULL,
            created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\',
            updated_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\',
            INDEX idx_roadmap_locale_status (locale, status),
            INDEX idx_roadmap_locale_kind (locale, kind),
            INDEX idx_roadmap_published (published_at),
            UNIQUE INDEX uniq_roadmap_slug_locale (slug, locale),
            PRIMARY KEY(id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

        $this->addSql("UPDATE menu_items SET url = '/tr/roadmap'
            WHERE url IN ('/#roadmap', '/tr/#roadmap', '#roadmap')");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE roadmap_entries');

        $this->addSql("UPDATE menu_items SET url = '/#roadmap' WHERE url = '/tr/roadmap'");
    }
}
