<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Yanlışlıkla silinen forum üst kayıtlarını geri yükler ve yetim kalan
 * panoları hiyerarşiye bağlar. Soft-delete yoktur; üst kayıt silindiğinde
 * alt panolar parent_id = NULL ile yetim kalır, konular silinmediyse korunur.
 */
final class Version20260827140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Silinen forum bölüm/kategori kayıtlarını geri yükler ve yetim panoları yeniden bağlar.';
    }

    public function up(Schema $schema): void
    {
        // Genel Forum (pub) — kök bölüm
        $this->addSql(<<<'SQL'
            INSERT INTO forum_sections (code, slug, locale, title, description, sort_order, is_container, allow_topics, section_type, created_at, updated_at)
            SELECT 'pub', 'genel', 'tr', 'Genel Forum', 'Herkese açık tartışma alanı', 0, 1, 0, 'division', NOW(), NOW()
            FROM forum_sections
            WHERE NOT EXISTS (SELECT 1 FROM forum_sections x WHERE x.code = 'pub')
            LIMIT 1
            SQL);

        // Akademik → pub altında kategori
        $this->addSql(<<<'SQL'
            UPDATE forum_sections a
            INNER JOIN forum_sections pub ON pub.code = 'pub'
            SET a.parent_id = pub.id,
                a.section_type = 'category',
                a.is_container = 1,
                a.allow_topics = 0,
                a.sort_order = 1
            WHERE a.code = 'akademik'
            SQL);

        $this->addSql(<<<'SQL'
            UPDATE forum_sections child
            INNER JOIN forum_sections akademik ON akademik.code = 'akademik'
            SET child.parent_id = akademik.id,
                child.section_type = 'subcategory',
                child.is_container = 0,
                child.allow_topics = 1
            WHERE child.code IN ('general', 'offtopic')
            SQL);

        // Makaleler kategorisi — ustbolum altına
        $this->addSql(<<<'SQL'
            INSERT INTO forum_sections (parent_id, code, slug, locale, title, description, sort_order, is_container, allow_topics, section_type, created_at, updated_at)
            SELECT u.id, 'makaleler-kat', 'makaleler-kategori', 'tr', 'Makaleler', 'Makale ve yazı panoları', 1, 1, 0, 'category', NOW(), NOW()
            FROM forum_sections u
            WHERE u.code = 'ustbolum'
              AND NOT EXISTS (SELECT 1 FROM forum_sections x WHERE x.code = 'makaleler-kat')
            SQL);

        $this->addSql(<<<'SQL'
            UPDATE forum_sections m
            INNER JOIN forum_sections k ON k.code = 'makaleler-kat'
            SET m.parent_id = k.id,
                m.section_type = 'subcategory',
                m.is_container = 0,
                m.allow_topics = 1
            WHERE m.code = 'makaleler-02'
            SQL);

        // Projeler kategorisi — ustbolum altına
        $this->addSql(<<<'SQL'
            INSERT INTO forum_sections (parent_id, code, slug, locale, title, description, sort_order, is_container, allow_topics, section_type, created_at, updated_at)
            SELECT u.id, 'proje-kat', 'projeler-kategori', 'tr', 'Projeler', 'Proje panoları', 2, 1, 0, 'category', NOW(), NOW()
            FROM forum_sections u
            WHERE u.code = 'ustbolum'
              AND NOT EXISTS (SELECT 1 FROM forum_sections x WHERE x.code = 'proje-kat')
            SQL);

        $this->addSql(<<<'SQL'
            UPDATE forum_sections p
            INNER JOIN forum_sections k ON k.code = 'proje-kat'
            SET p.parent_id = k.id,
                p.section_type = 'subcategory',
                p.is_container = 0,
                p.allow_topics = 1
            WHERE p.code = 'proje'
            SQL);

        // Alt kategori, alt kategori altında olamaz — Laravel'i kategori altına al
        $this->addSql(<<<'SQL'
            UPDATE forum_sections l
            INNER JOIN forum_sections k ON k.code = 'proje-kat'
            SET l.parent_id = k.id,
                l.section_type = 'subcategory',
                l.is_container = 0,
                l.allow_topics = 1,
                l.sort_order = 2
            WHERE l.code = 'laravel'
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            UPDATE forum_sections l
            INNER JOIN forum_sections p ON p.code = 'proje'
            SET l.parent_id = p.id
            WHERE l.code = 'laravel'
            SQL);

        $this->addSql(<<<'SQL'
            UPDATE forum_sections SET parent_id = NULL WHERE code IN ('makaleler-02', 'proje')
            SQL);

        $this->addSql(<<<'SQL'
            UPDATE forum_sections a SET a.parent_id = NULL, a.section_type = 'division' WHERE a.code = 'akademik'
            SQL);

        $this->addSql("DELETE FROM forum_sections WHERE code IN ('makaleler-kat', 'proje-kat')");
        $this->addSql("DELETE FROM forum_sections WHERE code = 'pub'");
    }
}
