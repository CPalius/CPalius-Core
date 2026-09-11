<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Adds Forum link to header/footer menus.
 */
final class Version20260825210000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Adds the Forum link (/tr/forum) to menus.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("INSERT INTO menu_items (menu_id, parent_id, label, locale, url, node_id, sort_order, open_in_new_tab)
            SELECT m.id, NULL, 'Forum', 'tr', '/tr/forum', NULL,
                COALESCE((SELECT MAX(mi.sort_order) + 1 FROM menu_items mi WHERE mi.menu_id = m.id), 0),
                0
            FROM menus m
            WHERE m.identifier IN ('header', 'footer')
              AND NOT EXISTS (
                  SELECT 1 FROM menu_items existing
                  WHERE existing.menu_id = m.id AND existing.url = '/tr/forum'
              )");
    }

    public function down(Schema $schema): void
    {
        $this->addSql("DELETE FROM menu_items WHERE url = '/tr/forum' AND label = 'Forum'");
    }
}
