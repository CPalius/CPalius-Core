<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Builds production CPalius CMF forum structure from scratch and redistributes existing topics to new boards.
 */
final class Version20260827150000 extends AbstractMigration
{
    /** @var list<string> */
    private const OLD_CODES = [
        'general', 'offtopic', 'makaleler-02', 'proje', 'laravel',
        'akademik', 'ustbolum', 'pub', 'makaleler-kat', 'proje-kat',
    ];

    public function getDescription(): string
    {
        return 'Creates the production CPalius CMF forum section/category/subcategory structure and redistributes existing topics.';
    }

    public function up(Schema $schema): void
    {
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');

        // Topic moves/deletes must run immediately — seed via connection, not queued addSql().
        $this->seedDivision('baslangic', 'baslangic', 'Başlangıç & Kurulum', 'Kurulum, gereksinimler ve ilk yapılandırma', 0, 'bi-rocket-takeoff', $now);
        $this->seedDivision('studio', 'studio', 'Studio — İçerik Yönetimi', 'Node, blog, medya ve menü yönetimi', 1, 'bi-pencil-square', $now);
        $this->seedDivision('gelistirme', 'gelistirme', 'Geliştirme', 'Modül, tema, migration ve API geliştirme', 2, 'bi-code-slash', $now);
        $this->seedDivision('aacp-sistem', 'aacp-sistem', 'AACP & Sistem', 'Performans, cron, güvenlik ve sistem yönetimi', 3, 'bi-shield-check', $now);
        $this->seedDivision('topluluk', 'topluluk', 'Topluluk', 'Tanışma, vitrin, geri bildirim ve serbest sohbet', 4, 'bi-people', $now);
        $this->seedDivision('destek', 'destek', 'Destek', 'Hata bildirimi, sorun giderme ve yardım', 5, 'bi-life-preserver', $now);

        // Getting started
        $this->seedCategory('baslangic', 'kurulum', 'kurulum', 'Kurulum', 'Sunucu gereksinimleri ve kurulum adımları', 0, 'bi-download', $now);
        $this->seedSubcategory('kurulum', 'gereksinimler', 'gereksinimler', 'Gereksinimler ve Ortam', 'PHP, Symfony, veritabanı ve sunucu gereksinimleri', 0, 'bi-cpu', $now);
        $this->seedSubcategory('kurulum', 'ilk-kurulum', 'ilk-kurulum', 'İlk Kurulum', 'Composer, .env ve ilk site kurulumu', 1, 'bi-play-circle', $now);

        $this->seedCategory('baslangic', 'yapilandirma', 'yapilandirma', 'Yapılandırma', 'Genel ayarlar ve yerelleştirme', 1, 'bi-gear', $now);
        $this->seedSubcategory('yapilandirma', 'genel-ayarlar', 'genel-ayarlar', 'Genel Ayarlar', 'Site ayarları, ana sayfa modu ve temel yapılandırma', 0, 'bi-sliders', $now);
        $this->seedSubcategory('yapilandirma', 'dil-yerellestirme', 'dil-yerellestirme', 'Dil ve Yerelleştirme', 'Locale, çeviri dosyaları ve çok dilli içerik', 1, 'bi-translate', $now);

        // Studio
        $this->seedCategory('studio', 'icerik', 'icerik', 'İçerik', 'Node tabanlı sayfa ve içerik yönetimi', 0, 'bi-file-earmark-text', $now);
        $this->seedSubcategory('icerik', 'sayfalar-nodlar', 'sayfalar-nodlar', 'Sayfalar ve Node\'lar', 'İçerik türleri, alanlar ve Node yönetimi', 0, 'bi-layout-text-window', $now);
        $this->seedSubcategory('icerik', 'kategoriler-etiketler', 'kategoriler-etiketler', 'Kategoriler ve Etiketler', 'Taksonomi, kategori ağacı ve etiketleme', 1, 'bi-tags', $now);

        $this->seedCategory('studio', 'moduller-studio', 'moduller', 'Modüller', 'Blog, medya ve menü modülleri', 1, 'bi-grid', $now);
        $this->seedSubcategory('moduller-studio', 'blog-modulu', 'blog', 'Blog Modülü', 'Yazılar, kategoriler, yorumlar ve blog ayarları', 0, 'bi-journal-text', $now);
        $this->seedSubcategory('moduller-studio', 'medya-modulu', 'medya', 'Medya Kütüphanesi', 'Dosya yükleme, medya seçici ve varlık yönetimi', 1, 'bi-images', $now);
        $this->seedSubcategory('moduller-studio', 'menu-modulu', 'menu', 'Menü Yönetimi', 'Header, footer ve özel menü yapılandırması', 2, 'bi-list-nested', $now);

        // Development
        $this->seedCategory('gelistirme', 'cekirdek-dev', 'cekirdek', 'Çekirdek', 'CPalius çekirdek mimarisi ve API\'ler', 0, 'bi-box', $now);
        $this->seedSubcategory('cekirdek-dev', 'mimari-kavramlar', 'mimari', 'Mimari ve Veri Modeli', 'Node/Resource modeli, hibrit alanlar ve indeksleme', 0, 'bi-diagram-3', $now);
        $this->seedSubcategory('cekirdek-dev', 'yetenek-sistemi', 'yetenekler', 'Yetenek ve İzin Sistemi', 'Capability tabanlı yetkilendirme ve roller', 1, 'bi-key', $now);

        $this->seedCategory('gelistirme', 'genisletme', 'genisletme', 'Genişletme', 'Modül, tema ve yapılandırma genişletme', 1, 'bi-puzzle', $now);
        $this->seedSubcategory('genisletme', 'ozel-moduller', 'modul-gelistirme', 'Özel Modül Geliştirme', 'Modül manifest, servisler ve hook sistemi', 0, 'bi-plugin', $now);
        $this->seedSubcategory('genisletme', 'tema-gelistirme', 'tema-gelistirme', 'Tema Geliştirme', 'Twig şablonları, AssetMapper ve tema.json', 1, 'bi-palette', $now);
        $this->seedSubcategory('genisletme', 'migration-config', 'migration-config', 'Migration ve Config Sync', 'Doctrine migration, YAML config ve deploy', 2, 'bi-arrow-repeat', $now);

        // AACP & system
        $this->seedCategory('aacp-sistem', 'performans', 'performans', 'Performans', 'Önbellek, RMVP ve optimizasyon', 0, 'bi-speedometer2', $now);
        $this->seedSubcategory('performans', 'onbellek-rmvp', 'onbellek', 'Önbellek ve RMVP', 'Redis, Memcached ve performans backend\'leri', 0, 'bi-lightning', $now);
        $this->seedSubcategory('performans', 'cron-gorevler', 'cron', 'Cron ve Arka Plan İşleri', 'Zamanlanmış görevler ve job yönetimi', 1, 'bi-clock-history', $now);

        $this->seedCategory('aacp-sistem', 'guvenlik-sistem', 'guvenlik', 'Güvenlik', 'Güvenlik, yedekleme ve URL yönetimi', 1, 'bi-lock', $now);
        $this->seedSubcategory('guvenlik-sistem', 'guvenlik-yedekleme', 'guvenlik-yedekleme', 'Güvenlik ve Yedekleme', 'Erişim kontrolü, yedekleme ve karantina', 0, 'bi-shield-lock', $now);
        $this->seedSubcategory('guvenlik-sistem', 'url-yonetimi', 'url-yonetimi', 'URL Alias Yönetimi', 'Özel URL tanımları ve yönlendirmeler', 1, 'bi-link-45deg', $now);

        // Community
        $this->seedCategory('topluluk', 'genel-topluluk', 'genel', 'Genel', 'Topluluk duyuruları ve tanışma', 0, 'bi-chat-dots', $now);
        $this->seedSubcategory('genel-topluluk', 'tanisma', 'tanisma', 'Tanışma', 'Kendinizi tanıtın, topluluğa katılın', 0, 'bi-hand-wave', $now);
        $this->seedSubcategory('genel-topluluk', 'duyurular', 'duyurular', 'Duyurular ve Haberler', 'CPalius sürüm notları ve topluluk haberleri', 1, 'bi-megaphone', $now);

        $this->seedCategory('topluluk', 'paylasim', 'paylasim', 'Paylaşım', 'Projeler ve geri bildirim', 1, 'bi-share', $now);
        $this->seedSubcategory('paylasim', 'proje-vitrini', 'proje-vitrini', 'Proje Vitrini', 'CPalius ile geliştirdiğiniz siteleri paylaşın', 0, 'bi-trophy', $now);
        $this->seedSubcategory('paylasim', 'geri-bildirim', 'geri-bildirim', 'Geri Bildirim ve Öneriler', 'Ürün önerileri ve iyileştirme fikirleri', 1, 'bi-lightbulb', $now);

        $this->seedSubcategory('topluluk', 'serbest-konusma', 'serbest', 'Serbest Konuşma', 'CMF dışı konular ve genel sohbet', 2, 'bi-cup-hot', $now);

        // Support
        $this->seedCategory('destek', 'yardim', 'yardim', 'Yardım', 'Teknik destek ve sorun giderme', 0, 'bi-question-circle', $now);
        $this->seedSubcategory('yardim', 'hata-bildirimi', 'hata-bildirimi', 'Hata Bildirimi', 'Bug raporları ve tekrarlanabilir adımlar', 0, 'bi-bug', $now);
        $this->seedSubcategory('yardim', 'sorun-giderme', 'sorun-giderme', 'Sorun Giderme ve SSS', 'Kurulum ve kullanım sorunları', 1, 'bi-wrench', $now);

        $this->redistributeTopics();
        $this->deleteOldSections();
        $this->resyncSectionStatsViaConnection();
    }

    public function down(Schema $schema): void
    {
        $this->addSql("DELETE FROM forum_sections WHERE code NOT IN ('".implode("','", self::OLD_CODES)."')");
    }

    private function seedDivision(
        string $code,
        string $slug,
        string $title,
        string $description,
        int $sortOrder,
        string $icon,
        string $now,
    ): void {
        $this->connection->executeStatement(
            "INSERT INTO forum_sections (code, slug, locale, title, description, icon, sort_order, is_container, allow_topics, section_type, created_at, updated_at)
             SELECT ?, ?, 'tr', ?, ?, ?, ?, 1, 0, 'division', ?, ?
             FROM DUAL
             WHERE NOT EXISTS (SELECT 1 FROM forum_sections x WHERE x.code = ?)",
            [$code, $slug, $title, $description, $icon, $sortOrder, $now, $now, $code],
        );
    }

    private function seedCategory(
        string $parentCode,
        string $code,
        string $slug,
        string $title,
        string $description,
        int $sortOrder,
        string $icon,
        string $now,
    ): void {
        $this->connection->executeStatement(
            "INSERT INTO forum_sections (parent_id, code, slug, locale, title, description, icon, sort_order, is_container, allow_topics, section_type, created_at, updated_at)
             SELECT p.id, ?, ?, 'tr', ?, ?, ?, ?, 1, 0, 'category', ?, ?
             FROM forum_sections p
             WHERE p.code = ?
               AND NOT EXISTS (SELECT 1 FROM forum_sections x WHERE x.code = ?)",
            [$code, $slug, $title, $description, $icon, $sortOrder, $now, $now, $parentCode, $code],
        );
    }

    private function seedSubcategory(
        string $parentCode,
        string $code,
        string $slug,
        string $title,
        string $description,
        int $sortOrder,
        string $icon,
        string $now,
    ): void {
        $this->connection->executeStatement(
            "INSERT INTO forum_sections (parent_id, code, slug, locale, title, description, icon, sort_order, is_container, allow_topics, section_type, created_at, updated_at)
             SELECT p.id, ?, ?, 'tr', ?, ?, ?, ?, 0, 1, 'subcategory', ?, ?
             FROM forum_sections p
             WHERE p.code = ?
               AND NOT EXISTS (SELECT 1 FROM forum_sections x WHERE x.code = ?)",
            [$code, $slug, $title, $description, $icon, $sortOrder, $now, $now, $parentCode, $code],
        );
    }

    private function redistributeTopics(): void
    {
        /** @var list<string> */
        $targetCodes = [
            'sayfalar-nodlar',
            'serbest-konusma',
            'ozel-moduller',
            'cron-gorevler',
            'proje-vitrini',
            'blog-modulu',
            'hata-bildirimi',
            'mimari-kavramlar',
            'tanisma',
            'sorun-giderme',
            'medya-modulu',
            'guvenlik-yedekleme',
        ];

        $topics = $this->connection->fetchAllAssociative('SELECT id FROM forum_topics ORDER BY id');
        if ($topics === []) {
            return;
        }

        foreach ($topics as $index => $topic) {
            $code = $targetCodes[$index % count($targetCodes)];
            $sectionId = $this->connection->fetchOne(
                'SELECT id FROM forum_sections WHERE code = ?',
                [$code],
            );

            if ($sectionId === false) {
                continue;
            }

            $topicId = (int) $topic['id'];
            $this->connection->executeStatement(
                'UPDATE forum_topics SET section_id = ? WHERE id = ?',
                [$sectionId, $topicId],
            );
            $this->connection->executeStatement(
                'UPDATE forum_posts SET section_id = ? WHERE topic_id = ?',
                [$sectionId, $topicId],
            );
        }
    }

    private function deleteOldSections(): void
    {
        $placeholders = implode(',', array_fill(0, count(self::OLD_CODES), '?'));
        $this->connection->executeStatement(
            "DELETE FROM forum_sections WHERE code IN ($placeholders)",
            self::OLD_CODES,
        );
    }

    private function resyncSectionStatsViaConnection(): void
    {
        $this->connection->executeStatement(<<<'SQL'
            UPDATE forum_sections s
            SET
                topic_count = (SELECT COUNT(*) FROM forum_topics t WHERE t.section_id = s.id),
                post_count = (SELECT COUNT(*) FROM forum_posts p WHERE p.section_id = s.id)
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

        $this->connection->executeStatement(<<<'SQL'
            UPDATE forum_sections s
            SET
                last_topic_id = NULL,
                last_topic_title = NULL,
                last_post_at = NULL,
                last_poster_name = NULL
            WHERE s.section_type = 'subcategory'
              AND s.post_count = 0
            SQL);
    }
}
