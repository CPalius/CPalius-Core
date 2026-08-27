<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Blog menü öğelerinin URL'lerini locale'siz /blog'dan kanonik /tr/blog
 * yoluna taşır (bkz. Blog modülü routes.yaml {_locale} prefix'i).
 */
final class Version20260825120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return "Menüdeki Blog linklerini /blog'dan /tr/blog'a günceller.";
    }

    public function up(Schema $schema): void
    {
        $this->addSql("UPDATE menu_items SET url = '/tr/blog' WHERE url = '/blog'");
    }

    public function down(Schema $schema): void
    {
        $this->addSql("UPDATE menu_items SET url = '/blog' WHERE url = '/tr/blog'");
    }
}
