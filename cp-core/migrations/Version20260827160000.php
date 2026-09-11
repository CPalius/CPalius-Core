<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Seeds sample forum topics and posts; restores content removed by Version20260827150000 ordering issue.
 */
final class Version20260827160000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Seeds sample topics and posts across CPalius CMF forum boards.';
    }

    public function up(Schema $schema): void
    {
        if ((int) $this->connection->fetchOne('SELECT COUNT(*) FROM forum_topics') > 0) {
            return;
        }

        $posterId = $this->connection->fetchOne('SELECT id FROM users ORDER BY id ASC LIMIT 1');
        $posterName = $this->connection->fetchOne('SELECT COALESCE(NULLIF(username, \'\'), email) FROM users ORDER BY id ASC LIMIT 1') ?: 'CPalius';
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');

        /** @var list<array{code: string, title: string, slug: string, body: string, replies: list<string>}> */
        $topics = [
            [
                'code' => 'sayfalar-nodlar',
                'title' => 'Node türleri ve hibrit alan modeli hakkında',
                'slug' => 'node-turleri-hibrit-alan',
                'body' => '<p>CPalius CMF\'de içerik <strong>Node</strong> entity\'si üzerinden yönetiliyor. Typed kolonlar + JSON alanlar birlikte kullanılıyor. Studio\'dan yeni bir içerik türü tanımlarken hangi alanların indeksleneceğini nasıl seçiyorsunuz?</p>',
                'replies' => [
                    '<p>Ben blog yazıları için kategori ilişkisini typed, özel meta alanlarını JSON\'da tutuyorum. Flat index sayesinde filtreleme hızlı.</p>',
                    '<p>Drupal\'daki Field API\'ye benziyor ama Symfony entity katmanında daha hafif. Dokümantasyonu genişletmek iyi olur.</p>',
                ],
            ],
            [
                'code' => 'serbest-konusma',
                'title' => 'Eski CMS\'lerden CPalius\'a geçiş deneyimleri',
                'slug' => 'cms-gecis-deneyimleri',
                'body' => '<p>WordPress veya Drupal kullananlar: CPalius\'a geçişte en çok zorlandığınız konu ne oldu? Ben modül manifest yapısına alışmak biraz zaman aldı.</p>',
                'replies' => [
                    '<p>Twig şablonları tanıdık geldi. AssetMapper ile frontend build zinciri olmaması büyük artı.</p>',
                ],
            ],
            [
                'code' => 'ozel-moduller',
                'title' => 'Özel modülde hook ve event kullanımı',
                'slug' => 'ozel-modul-hook-event',
                'body' => '<p><code>module.json</code> manifest, servis tanımları ve hook sistemi hakkında pratik örnekler paylaşalım. İlk modülünüzü nasıl yapılandırdınız?</p>',
                'replies' => [
                    '<p>Forum modülü iyi referans. Capability yaml + admin controller pattern\'i kopyalayarak başladım.</p>',
                    '<p>Symfony event subscriber ile core\'a müdahale etmek Temiz görünüyor.</p>',
                ],
            ],
            [
                'code' => 'cron-gorevler',
                'title' => 'Cron görevleri ve arka plan işleri',
                'slug' => 'cron-arka-plan-isleri',
                'body' => '<p>AACP Cron yönetiminde dinamik job tanımlama nasıl çalışıyor? Sunucu <em>server status</em> endpoint\'i ile entegrasyon kuran var mı?</p>',
                'replies' => [
                    '<p>Henüz prod\'da denemedim ama cp_cron_jobs tablosu üzerinden yönetim mantıklı görünüyor.</p>',
                ],
            ],
            [
                'code' => 'proje-vitrini',
                'title' => 'CPalius ile kurduğumuz kurumsal site',
                'slug' => 'cpalius-kurumsal-site',
                'body' => '<p>Blog + Forum + özel Node türleri ile kurumsal vitrin sitesi yayına aldık. Tema: cpalius-website. Geri bildirimlerinizi bekliyorum.</p>',
                'replies' => [],
            ],
            [
                'code' => 'blog-modulu',
                'title' => 'Blog sidebar ve kategori sayfalama',
                'slug' => 'blog-sidebar-kategori',
                'body' => '<p>Blog modülünde kategori sayfalarında sidebar genişliği ve listeleme performansı üzerine tartışalım.</p>',
                'replies' => [
                    '<p>N+1 sorgu sorunları settings tarafında da vardı, batch load ile çözüldü. Blog tarafında da benzer pattern kullanılabilir.</p>',
                ],
            ],
            [
                'code' => 'hata-bildirimi',
                'title' => 'Windows ortamında uygulama bulunamadı hatası',
                'slug' => 'windows-uygulama-bulunamadi',
                'body' => '<p>Laragon üzerinde <strong>php bin/console</strong> çalıştırırken Windows bazen &quot;Uygulama Bulunamadı&quot; veriyor. PATH veya php.ini kaynaklı olabilir mi?</p>',
                'replies' => [],
            ],
            [
                'code' => 'mimari-kavramlar',
                'title' => 'Resource entity ve CRM senaryoları',
                'slug' => 'resource-entity-crm',
                'body' => '<p>Business Resource entity\'si CRM/ERP kayıtları için tasarlanmış. Node\'dan farkı ve kullanım senaryoları neler?</p>',
                'replies' => [
                    '<p>Node = içerik, Resource = iş kaydı ayrımı Typo3 Page/Record mantığına yakın.</p>',
                ],
            ],
        ];

        foreach ($topics as $index => $topic) {
            $sectionId = $this->connection->fetchOne(
                'SELECT id FROM forum_sections WHERE code = ? AND section_type = \'subcategory\'',
                [$topic['code']],
            );

            if ($sectionId === false) {
                continue;
            }

            $createdAt = (new \DateTimeImmutable(sprintf('-%d days', 14 - $index)))->format('Y-m-d H:i:s');
            $postCount = 1 + count($topic['replies']);

            $this->connection->executeStatement(
                'INSERT INTO forum_topics (section_id, title, slug, mode, state, sticky, view_count, post_count, first_poster_id, first_poster_name, last_poster_id, last_poster_name, preview, created_at, updated_at)
                 VALUES (?, ?, ?, 0, 0, 0, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [
                    $sectionId,
                    $topic['title'],
                    $topic['slug'],
                    ($index + 1) * 7,
                    $postCount,
                    $posterId,
                    $posterName,
                    $posterId,
                    $posterName,
                    mb_substr(strip_tags($topic['body']), 0, 128),
                    $createdAt,
                    $now,
                ],
            );

            $topicId = (int) $this->connection->lastInsertId();

            $this->insertPost($topicId, (int) $sectionId, $posterId, $posterName, $topic['body'], $createdAt);

            foreach ($topic['replies'] as $replyIndex => $replyBody) {
                $replyAt = (new \DateTimeImmutable($createdAt))->modify(sprintf('+%d hours', $replyIndex + 1))->format('Y-m-d H:i:s');
                $this->insertPost($topicId, (int) $sectionId, $posterId, $posterName, $replyBody, $replyAt);
            }
        }

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
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DELETE FROM forum_posts');
        $this->addSql('DELETE FROM forum_topics');
        $this->addSql(<<<'SQL'
            UPDATE forum_sections SET
                topic_count = 0,
                post_count = 0,
                last_topic_id = NULL,
                last_topic_title = NULL,
                last_post_at = NULL,
                last_poster_name = NULL
            SQL);
    }

    private function insertPost(
        int $topicId,
        int $sectionId,
        mixed $authorId,
        string $posterName,
        string $body,
        string $createdAt,
    ): void {
        $this->connection->executeStatement(
            'INSERT INTO forum_posts (topic_id, section_id, author_id, poster_name, body, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?)',
            [$topicId, $sectionId, $authorId, $posterName, $body, $createdAt, $createdAt],
        );
    }
}
