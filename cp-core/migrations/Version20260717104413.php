<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Moves hardcoded cpalius-website header/footer nav links into Menu/MenuItem records (header, footer, footer_contact).
 * Anchor links are stored as /#fragment absolute URLs; template {% else %} branches remain as fail-safe fallbacks.
 */
final class Version20260717104413 extends AbstractMigration
{
    public function getDescription(): string
    {
        return "Moves hardcoded theme navigation links into 'header'/'footer'/'footer_contact' Menu records.";
    }

    public function up(Schema $schema): void
    {
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');

        $menus = [
            ['name' => 'Üst Menü (Header)', 'identifier' => 'header'],
            ['name' => 'Alt Menü (Footer - Hızlı Erişim)', 'identifier' => 'footer'],
            ['name' => 'Alt Menü (Footer - İletişim)', 'identifier' => 'footer_contact'],
        ];

        $menuIds = [];
        foreach ($menus as $menu) {
            // executeStatement() runs synchronously so lastInsertId() is safe (addSql() would queue until end).
            $this->connection->executeStatement(
                'INSERT INTO menus (name, identifier) VALUES (?, ?)',
                [$menu['name'], $menu['identifier']],
            );
            $menuIds[$menu['identifier']] = (int) $this->connection->lastInsertId();
        }

        $headerItems = [
            ['Ana Sayfa', '/'],
            ['Blog', '/tr/blog'],
            ['Forum', '/tr/forum'],
        ];
        $this->insertItems($menuIds['header'], $headerItems, $now);

        $footerItems = [
            ['Blog', '/tr/blog'],
            ['Forum', '/tr/forum'],
        ];
        $this->insertItems($menuIds['footer'], $footerItems, $now);

        $footerContactItems = [
            ['GitHub', '#'],
            ['İletişim', '#'],
        ];
        $this->insertItems($menuIds['footer_contact'], $footerContactItems, $now);
    }

    /**
     * @param list<array{0: string, 1: string}> $items
     */
    private function insertItems(int $menuId, array $items, string $now): void
    {
        foreach ($items as $sortOrder => [$label, $url]) {
            $this->connection->executeStatement(
                'INSERT INTO menu_items (menu_id, parent_id, label, locale, url, node_id, sort_order, open_in_new_tab) VALUES (?, NULL, ?, ?, ?, NULL, ?, 0)',
                [$menuId, $label, 'tr', $url, $sortOrder],
            );
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql("DELETE FROM menu_items WHERE menu_id IN (SELECT id FROM menus WHERE identifier IN ('header', 'footer', 'footer_contact'))");
        $this->addSql("DELETE FROM menus WHERE identifier IN ('header', 'footer', 'footer_contact')");
    }
}
