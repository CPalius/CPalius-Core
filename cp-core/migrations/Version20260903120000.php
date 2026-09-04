<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * FAZ 3 — Modüler çeviri altyapısı.
 *
 * TranslatableTrait'in getirdiği translation_group_id kolonunu, arayüzü
 * uygulayan dört entity'nin tablosuna ekler: categories, tags, menu_items,
 * forum_sections.
 *
 * Kolon tipi BINARY(16): Symfony\Bridge\Doctrine\Types\UuidType'ın MySQL
 * karşılığıdır ve nodes.translation_group_id ile BİREBİR AYNIDIR (bkz.
 * Version20260714200233) — UUID'yi CHAR(36) olarak tutmak indeks başına
 * ~2,25 kat yer kaplar ve karşılaştırmayı yavaşlatır.
 *
 * NULLABLE'dır ve varsayılan değeri yoktur: mevcut satırlar hiçbir çeviri
 * grubuna dahil olmadan, olduğu gibi kalır. Bu migration VERİ YAZMAZ,
 * dolayısıyla geri alınabilir ve büyük tablolarda kilit süresi yalnızca
 * kolon/indeks ekleme kadardır.
 *
 * nodes tablosundaki UNIQUE (translation_group_id, locale) kısıtının
 * BURADA KARŞILIĞI YOKTUR ve bu bilinçlidir: menu_items aynı menüde aynı
 * dilde birden fazla öğe taşıyabilir; kategoriler/etiketler için de aynı
 * grubun aynı dilde iki kaydı geçici bir düzenleme durumu olabilir. Katı
 * kısıt yerine indeks (arama hızı) tercih edilmiştir.
 */
final class Version20260903120000 extends AbstractMigration
{
    /**
     * @var array<string, string> tablo => indeks adı
     */
    private const TABLES = [
        'categories' => 'idx_category_translation_group',
        'tags' => 'idx_tag_translation_group',
        'menu_items' => 'idx_menu_item_translation_group',
        'forum_sections' => 'idx_forum_section_translation_group',
    ];

    public function getDescription(): string
    {
        return 'FAZ 3: adds translation_group_id (UUID v7, BINARY(16)) to categories, tags, menu_items and forum_sections.';
    }

    public function up(Schema $schema): void
    {
        foreach (self::TABLES as $table => $indexName) {
            $this->addSql(sprintf(
                'ALTER TABLE %s ADD translation_group_id BINARY(16) DEFAULT NULL COMMENT \'(DC2Type:uuid)\'',
                $table,
            ));

            $this->addSql(sprintf(
                'CREATE INDEX %s ON %s (translation_group_id)',
                $indexName,
                $table,
            ));
        }
    }

    public function down(Schema $schema): void
    {
        foreach (self::TABLES as $table => $indexName) {
            $this->addSql(sprintf('DROP INDEX %s ON %s', $indexName, $table));
            $this->addSql(sprintf('ALTER TABLE %s DROP translation_group_id', $table));
        }
    }
}
