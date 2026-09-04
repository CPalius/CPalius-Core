<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * FAZ 4 — Çeviri grubu bütünlük kısıtı.
 *
 * Version20260903120000 translation_group_id kolonunu ve düz bir arama
 * indeksini eklemişti. Bu migration o indeksi UNIQUE(translation_group_id,
 * locale) kısıtıyla DEĞİŞTİRİR ve böylece nodes tablosundaki
 * uniq_node_translation_group_locale ile aynı garantiyi dört tabloya daha
 * taşır: BİR ÇEVİRİ GRUBUNDA HER DİLDEN EN FAZLA BİR KAYIT olabilir.
 *
 * Bu, uygulama katmanındaki kontrolün (bkz. CategoryAdminController,
 * ForumAacpController ve MenuAdminController::translateItem — hepsi
 * "bu dilde çeviri zaten var" kontrolü yapar) veritabanı seviyesindeki
 * karşılığıdır: iki eşzamanlı istek aynı anda aynı dilde çeviri
 * oluşturmaya çalışırsa PHP tarafındaki kontrol yarış koşuluna (race
 * condition) girebilir, bu kısıt ise giremez.
 *
 * DÜZ İNDEKS AYRICA GEREKMEZ: MySQL/PostgreSQL çok kolonlu bir indeksin
 * ÖNDEKİ kolonu (translation_group_id) üzerinden yapılan aramalarda o
 * indeksi kullanabilir — TranslationGroupResolver'ın "aynı gruptaki tüm
 * kayıtlar" sorgusu bu kısıttan tam olarak yararlanır.
 *
 * NULL GÜVENLİDİR: hem MySQL hem PostgreSQL, UNIQUE kısıtlarında birden
 * çok NULL'a izin verir. Yani "henüz hiçbir çeviri grubuna dahil olmayan"
 * (translation_group_id IS NULL) sınırsız sayıda kayıt aynı dilde var
 * olmaya devam eder — mevcut veri bu migration'dan etkilenmez.
 */
final class Version20260903150000 extends AbstractMigration
{
    /**
     * @var array<string, array{0: string, 1: string}> tablo => [eski indeks, yeni unique kısıt]
     */
    private const TABLES = [
        'categories' => ['idx_category_translation_group', 'uniq_category_translation_group_locale'],
        'tags' => ['idx_tag_translation_group', 'uniq_tag_translation_group_locale'],
        'menu_items' => ['idx_menu_item_translation_group', 'uniq_menu_item_translation_group_locale'],
        'forum_sections' => ['idx_forum_section_translation_group', 'uniq_forum_section_translation_group_locale'],
    ];

    public function getDescription(): string
    {
        return 'FAZ 4: replaces the translation_group_id index with UNIQUE(translation_group_id, locale) on categories, tags, menu_items and forum_sections.';
    }

    public function up(Schema $schema): void
    {
        foreach (self::TABLES as $table => [$indexName, $uniqueName]) {
            $this->addSql(sprintf('DROP INDEX %s ON %s', $indexName, $table));
            $this->addSql(sprintf(
                'CREATE UNIQUE INDEX %s ON %s (translation_group_id, locale)',
                $uniqueName,
                $table,
            ));
        }
    }

    public function down(Schema $schema): void
    {
        foreach (self::TABLES as $table => [$indexName, $uniqueName]) {
            $this->addSql(sprintf('DROP INDEX %s ON %s', $uniqueName, $table));
            $this->addSql(sprintf('CREATE INDEX %s ON %s (translation_group_id)', $indexName, $table));
        }
    }
}
