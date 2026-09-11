<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Simplifies forum structure to 3 divisions and 12 board subcategories.
 */
final class Version20260827170000 extends AbstractMigration
{
    /** @var list<string> */
    private const KEEP_CODES = [
        'baslangic', 'kullanim', 'topluluk',
        'kurulum', 'yapilandirma', 'studio-icerik', 'blog-medya',
        'modul-gelistirme', 'tema-migration', 'tanisma', 'proje-vitrini',
        'geri-bildirim', 'serbest-konusma', 'destek-hatalar', 'sistem-performans',
    ];

    public function getDescription(): string
    {
        return 'Simplifies the forum structure to 3 sections and 12 subcategories.';
    }

    public function up(Schema $schema): void
    {
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');

        $this->moveTopics('mimari-kavramlar', 'sayfalar-nodlar');

        $this->renameBoard('sayfalar-nodlar', 'studio-icerik', 'studio-icerik', 'Studio & İçerik', 'Node, sayfa ve içerik yönetimi', 'bi-layout-text-window');
        $this->renameBoard('blog-modulu', 'blog-medya', 'blog-medya', 'Blog & Medya', 'Blog modülü ve medya kütüphanesi', 'bi-journal-text');
        $this->renameBoard('ozel-moduller', 'modul-gelistirme', 'modul-gelistirme', 'Modül Geliştirme', 'Özel modüller, hook ve API', 'bi-plugin');
        $this->renameBoard('cron-gorevler', 'sistem-performans', 'sistem-performans', 'Sistem & Performans', 'AACP, cron ve önbellek', 'bi-speedometer2');
        $this->renameBoard('hata-bildirimi', 'destek-hatalar', 'destek-hatalar', 'Destek & Hatalar', 'Bug bildirimi ve sorun giderme', 'bi-life-preserver');
        $this->renameBoard('tema-gelistirme', 'tema-migration', 'tema-migration', 'Tema & Migration', 'Tema geliştirme ve config sync', 'bi-palette');

        $this->upsertDivision('baslangic', 'baslangic', 'Başlangıç', 'Kurulum ve yapılandırma', 0, 'bi-rocket-takeoff', $now);
        $this->upsertDivision('kullanim', 'kullanim', 'CPalius Kullanım', 'Studio, modül ve tema geliştirme', 1, 'bi-pencil-square', $now);
        $this->upsertDivision('topluluk', 'topluluk', 'Topluluk & Destek', 'Sohbet, vitrin ve yardım', 2, 'bi-people', $now);

        $this->convertCategoryToBoard('kurulum', 'baslangic', 'Kurulum', 'Gereksinimler, Composer ve ilk kurulum', 0, 'bi-download', $now);
        $this->convertCategoryToBoard('yapilandirma', 'baslangic', 'Yapılandırma', 'Ayarlar, dil ve yerelleştirme', 1, 'bi-gear', $now);

        $kullanimId = $this->divisionId('kullanim');
        $toplulukId = $this->divisionId('topluluk');

        $this->reparentBoard('studio-icerik', $kullanimId, 0);
        $this->reparentBoard('blog-medya', $kullanimId, 1);
        $this->reparentBoard('modul-gelistirme', $kullanimId, 2);
        $this->reparentBoard('tema-migration', $kullanimId, 3);
        $this->reparentBoard('sistem-performans', $kullanimId, 4);

        $this->reparentBoard('tanisma', $toplulukId, 0);
        $this->reparentBoard('proje-vitrini', $toplulukId, 1);
        $this->reparentBoard('geri-bildirim', $toplulukId, 2);
        $this->reparentBoard('serbest-konusma', $toplulukId, 3);
        $this->reparentBoard('destek-hatalar', $toplulukId, 4);

        $this->removeOldSections();
        $this->resyncStats();
    }

    public function down(Schema $schema): void
    {
    }

    private function divisionId(string $code): int
    {
        return (int) $this->connection->fetchOne('SELECT id FROM forum_sections WHERE code = ?', [$code]);
    }

    private function moveTopics(string $fromCode, string $toCode): void
    {
        $fromId = $this->connection->fetchOne('SELECT id FROM forum_sections WHERE code = ?', [$fromCode]);
        $toId = $this->connection->fetchOne('SELECT id FROM forum_sections WHERE code = ?', [$toCode]);

        if ($fromId === false || $toId === false) {
            return;
        }

        $this->connection->executeStatement(
            'UPDATE forum_topics SET section_id = ? WHERE section_id = ?',
            [$toId, $fromId],
        );
        $this->connection->executeStatement(
            'UPDATE forum_posts SET section_id = ? WHERE section_id = ?',
            [$toId, $fromId],
        );
    }

    private function renameBoard(
        string $oldCode,
        string $newCode,
        string $slug,
        string $title,
        string $description,
        string $icon,
    ): void {
        $this->connection->executeStatement(
            'UPDATE forum_sections SET code = ?, slug = ?, title = ?, description = ?, icon = ? WHERE code = ?',
            [$newCode, $slug, $title, $description, $icon, $oldCode],
        );
    }

    private function upsertDivision(
        string $code,
        string $slug,
        string $title,
        string $description,
        int $sortOrder,
        string $icon,
        string $now,
    ): void {
        $exists = (int) $this->connection->fetchOne('SELECT COUNT(*) FROM forum_sections WHERE code = ?', [$code]);
        if ($exists > 0) {
            $this->connection->executeStatement(
                'UPDATE forum_sections SET slug = ?, title = ?, description = ?, icon = ?, sort_order = ?,
                    parent_id = NULL, section_type = \'division\', is_container = 1, allow_topics = 0, updated_at = ?
                 WHERE code = ?',
                [$slug, $title, $description, $icon, $sortOrder, $now, $code],
            );

            return;
        }

        $this->connection->executeStatement(
            'INSERT INTO forum_sections (code, slug, locale, title, description, icon, sort_order, is_container, allow_topics, section_type, created_at, updated_at)
             VALUES (?, ?, \'tr\', ?, ?, ?, ?, 1, 0, \'division\', ?, ?)',
            [$code, $slug, $title, $description, $icon, $sortOrder, $now, $now],
        );
    }

    private function convertCategoryToBoard(
        string $code,
        string $parentDivisionCode,
        string $title,
        string $description,
        int $sortOrder,
        string $icon,
        string $now,
    ): void {
        $parentId = $this->divisionId($parentDivisionCode);

        $this->connection->executeStatement(
            'UPDATE forum_sections SET parent_id = ?, title = ?, description = ?, icon = ?, sort_order = ?,
                section_type = \'subcategory\', is_container = 0, allow_topics = 1, updated_at = ?
             WHERE code = ?',
            [$parentId, $title, $description, $icon, $sortOrder, $now, $code],
        );
    }

    private function reparentBoard(string $code, int $parentId, int $sortOrder): void
    {
        $this->connection->executeStatement(
            'UPDATE forum_sections SET parent_id = ?, sort_order = ?,
                section_type = \'subcategory\', is_container = 0, allow_topics = 1
             WHERE code = ?',
            [$parentId, $sortOrder, $code],
        );
    }

    private function removeOldSections(): void
    {
        $placeholders = implode(',', array_fill(0, count(self::KEEP_CODES), '?'));
        $this->connection->executeStatement(
            "DELETE FROM forum_sections WHERE code NOT IN ($placeholders)",
            self::KEEP_CODES,
        );
    }

    private function resyncStats(): void
    {
        $this->connection->executeStatement(<<<'SQL'
            UPDATE forum_sections s
            SET
                topic_count = (SELECT COUNT(*) FROM forum_topics t WHERE t.section_id = s.id),
                post_count = (SELECT COUNT(*) FROM forum_posts p WHERE p.section_id = s.id),
                last_topic_id = NULL,
                last_topic_title = NULL,
                last_post_at = NULL,
                last_poster_name = NULL
            SQL);

        $this->connection->executeStatement(<<<'SQL'
            UPDATE forum_sections s
            INNER JOIN (
                SELECT
                    p.section_id,
                    p.created_at AS last_post_at,
                    p.poster_name AS last_poster_name,
                    t.id AS last_topic_id,
                    t.title AS last_topic_title
                FROM forum_posts p
                INNER JOIN forum_topics t ON t.id = p.topic_id
                INNER JOIN (
                    SELECT section_id, MAX(created_at) AS max_created
                    FROM forum_posts
                    GROUP BY section_id
                ) latest ON latest.section_id = p.section_id AND latest.max_created = p.created_at
            ) lp ON lp.section_id = s.id
            SET
                s.last_post_at = lp.last_post_at,
                s.last_poster_name = lp.last_poster_name,
                s.last_topic_id = lp.last_topic_id,
                s.last_topic_title = lp.last_topic_title
            SQL);
    }
}
