<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * cpalius-website temasının header.html.twig/footer.html.twig dosyalarında
 * BUGÜNE KADAR hardcode olarak yazılan gezinme linklerini (bkz. cp_menu()
 * çağrılarının {% else %} fallback dalları) gerçek Menu/MenuItem
 * kayıtlarına taşır — 'header', 'footer' ve yeni 'footer_contact'
 * identifier'larıyla üç Menu ve onların item'ları oluşturulur.
 *
 * Bu satır sonrası artık AACP > Görünüm > Menüler ekranından
 * düzenlenebilir/silinebilir hale gelirler; şablonlardaki hardcode
 * {% else %} dalları SADECE bu menüler bir şekilde tamamen silinirse
 * devreye giren bir fail-safe fallback olarak kalır (bkz. FrontMenuRuntime).
 *
 * '#' ile başlayan anchor linkler ('#about' vb.) kasıtlı olarak
 * '/#about' şeklinde ana sayfaya göre MUTLAK yazılır — eski şablondaki
 * {{ path('theme_cpalius_website_home') }}#about ifadesiyle birebir aynı
 * hedefe gitmesi için (menü öğeleri statik url alanı kullanır, route adı
 * çözümleyemez).
 */
final class Version20260717104413 extends AbstractMigration
{
    public function getDescription(): string
    {
        return "Tema navigasyonundaki hardcode linkleri 'header'/'footer'/'footer_contact' Menu kayıtlarına taşır.";
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
            // addSql() bu ifadeyi hemen ÇALIŞTIRMAZ, migration sonunda
            // sıraya konmuş halde toplu çalıştırılır — bu yüzden hemen
            // ardından lastInsertId() okumak yarış koşuluna girer.
            // executeStatement() senkron çalışır, ID'yi güvenle okuyabiliriz.
            $this->connection->executeStatement(
                'INSERT INTO menus (name, identifier) VALUES (?, ?)',
                [$menu['name'], $menu['identifier']],
            );
            $menuIds[$menu['identifier']] = (int) $this->connection->lastInsertId();
        }

        $headerItems = [
            ['Hakkında', '/#about'],
            ['Mimari', '/#architecture'],
            ['Çekirdek', '/#core'],
            ['Özellikler', '/#features'],
            ['Varlık Modeli', '/#entity'],
            ['Güvenlik', '/#security'],
            ['Yol Haritası', '/#roadmap'],
            ['Blog', '/tr/blog'],
        ];
        $this->insertItems($menuIds['header'], $headerItems, $now);

        $footerItems = [
            ['Hakkında', '/#about'],
            ['Blog', '/tr/blog'],
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
