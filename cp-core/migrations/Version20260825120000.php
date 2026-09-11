<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Moves Blog menu item URLs from locale-less /blog to canonical /tr/blog (Blog routes.yaml {_locale} prefix).
 */
final class Version20260825120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return "Updates Blog menu links from /blog to /tr/blog.";
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
