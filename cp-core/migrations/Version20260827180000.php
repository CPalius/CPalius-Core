<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Forum demo: 6 users, 250+ word topics across 12 boards, and multiple replies.
 */
final class Version20260827180000 extends AbstractMigration
{
    private const PASSWORD_HASH = '$2y$13$FEPVDhD0RzrEVL9vKvv3Muo8lPlN/1UITh2JLbBvPWfMO9xo7iFOC';

    public function getDescription(): string
    {
        return 'Adds forum demo users, 250+ word topics, and multiple replies.';
    }

    public function up(Schema $schema): void
    {
        if ($this->connection->fetchOne("SELECT id FROM users WHERE email = 'ayse@forum.cpalius.local'") !== false) {
            return;
        }

        $now = new \DateTimeImmutable();
        $userIds = $this->seedUsers($now);

        /** @var list<array{section: string, title: string, slug: string, author: int, body: string, replies: list<array{author: int, body: string}>}> */
        $topics = [
            [
                'section' => 'kurulum',
                'title' => 'Laragon üzerinde CPalius CMF kurulum rehberi ve ilk adımlar',
                'slug' => 'laragon-cpalius-kurulum-rehberi',
                'author' => $userIds['ayse'],
                'body' => $this->body('kurulum'),
                'replies' => [
                    ['author' => $userIds['mehmet'], 'body' => $this->body('kurulum-reply-1', 120)],
                    ['author' => $userIds['can'], 'body' => $this->body('kurulum-reply-2', 120)],
                ],
            ],
            [
                'section' => 'yapilandirma',
                'title' => 'Çok dilli site yapılandırması ve locale ayarları',
                'slug' => 'cok-dilli-site-yapilandirma',
                'author' => $userIds['zeynep'],
                'body' => $this->body('yapilandirma'),
                'replies' => [
                    ['author' => $userIds['ela'], 'body' => $this->body('yapilandirma-reply-1', 120)],
                    ['author' => $userIds['burak'], 'body' => $this->body('yapilandirma-reply-2', 120)],
                ],
            ],
            [
                'section' => 'studio-icerik',
                'title' => 'Node türleri, hibrit alan modeli ve Studio içerik akışı',
                'slug' => 'node-hibrit-alan-studio',
                'author' => $userIds['mehmet'],
                'body' => $this->body('studio'),
                'replies' => [
                    ['author' => $userIds['ayse'], 'body' => $this->body('studio-reply-1', 120)],
                    ['author' => $userIds['can'], 'body' => $this->body('studio-reply-2', 120)],
                ],
            ],
            [
                'section' => 'blog-medya',
                'title' => 'Blog modülü kategori yapısı ve medya kütüphanesi entegrasyonu',
                'slug' => 'blog-medya-entegrasyon',
                'author' => $userIds['ela'],
                'body' => $this->body('blog'),
                'replies' => [
                    ['author' => $userIds['zeynep'], 'body' => $this->body('blog-reply-1', 120)],
                    ['author' => $userIds['burak'], 'body' => $this->body('blog-reply-2', 120)],
                ],
            ],
            [
                'section' => 'modul-gelistirme',
                'title' => 'İlk özel modülünüz: module.json, servisler ve capability tanımları',
                'slug' => 'ilk-ozel-modul-rehberi',
                'author' => $userIds['can'],
                'body' => $this->body('modul'),
                'replies' => [
                    ['author' => $userIds['mehmet'], 'body' => $this->body('modul-reply-1', 120)],
                    ['author' => $userIds['ayse'], 'body' => $this->body('modul-reply-2', 120)],
                ],
            ],
            [
                'section' => 'tema-migration',
                'title' => 'Tema geliştirme, AssetMapper ve Doctrine migration pratikleri',
                'slug' => 'tema-assetmapper-migration',
                'author' => $userIds['burak'],
                'body' => $this->body('tema'),
                'replies' => [
                    ['author' => $userIds['ela'], 'body' => $this->body('tema-reply-1', 120)],
                    ['author' => $userIds['can'], 'body' => $this->body('tema-reply-2', 120)],
                ],
            ],
            [
                'section' => 'sistem-performans',
                'title' => 'AACP cron yönetimi, önbellek backend\'leri ve RMVP',
                'slug' => 'aacp-cron-onbellek-rmvp',
                'author' => $userIds['burak'],
                'body' => $this->body('sistem'),
                'replies' => [
                    ['author' => $userIds['mehmet'], 'body' => $this->body('sistem-reply-1', 120)],
                    ['author' => $userIds['zeynep'], 'body' => $this->body('sistem-reply-2', 120)],
                ],
            ],
            [
                'section' => 'tanisma',
                'title' => 'Merhaba! Drupal geçmişimden CPalius\'a yolculuğum',
                'slug' => 'drupal-den-cpaliusa-tanisma',
                'author' => $userIds['ayse'],
                'body' => $this->body('tanisma'),
                'replies' => [
                    ['author' => $userIds['ela'], 'body' => $this->body('tanisma-reply-1', 120)],
                    ['author' => $userIds['can'], 'body' => $this->body('tanisma-reply-2', 120)],
                ],
            ],
            [
                'section' => 'proje-vitrini',
                'title' => 'CPalius ile kurduğumuz belediye portalı — mimari ve sonuçlar',
                'slug' => 'belediye-portali-vitrin',
                'author' => $userIds['zeynep'],
                'body' => $this->body('vitrin'),
                'replies' => [
                    ['author' => $userIds['burak'], 'body' => $this->body('vitrin-reply-1', 120)],
                    ['author' => $userIds['mehmet'], 'body' => $this->body('vitrin-reply-2', 120)],
                ],
            ],
            [
                'section' => 'geri-bildirim',
                'title' => 'Studio dashboard ve forum modülü için önerilerim',
                'slug' => 'studio-forum-oneriler',
                'author' => $userIds['ela'],
                'body' => $this->body('geri-bildirim'),
                'replies' => [
                    ['author' => $userIds['ayse'], 'body' => $this->body('geri-bildirim-reply-1', 120)],
                    ['author' => $userIds['can'], 'body' => $this->body('geri-bildirim-reply-2', 120)],
                ],
            ],
            [
                'section' => 'serbest-konusma',
                'title' => 'CMF dünyasında 2026: Symfony 7, AI araçları ve topluluk',
                'slug' => 'cmf-2026-sohbet',
                'author' => $userIds['can'],
                'body' => $this->body('serbest'),
                'replies' => [
                    ['author' => $userIds['ela'], 'body' => $this->body('serbest-reply-1', 120)],
                    ['author' => $userIds['burak'], 'body' => $this->body('serbest-reply-2', 120)],
                ],
            ],
            [
                'section' => 'destek-hatalar',
                'title' => 'Windows + Laragon ortamında console hataları ve çözümleri',
                'slug' => 'windows-laragon-console-hatalari',
                'author' => $userIds['mehmet'],
                'body' => $this->body('destek'),
                'replies' => [
                    ['author' => $userIds['burak'], 'body' => $this->body('destek-reply-1', 120)],
                    ['author' => $userIds['ayse'], 'body' => $this->body('destek-reply-2', 120)],
                ],
            ],
        ];

        foreach ($topics as $index => $topic) {
            $this->insertTopicWithReplies($topic, $now->modify(sprintf('-%d days', 30 - $index)));
        }

        $this->replyToExistingTopics($userIds, $now);
        $this->resyncStats();
    }

    public function down(Schema $schema): void
    {
        $emails = [
            'ayse@forum.cpalius.local', 'mehmet@forum.cpalius.local', 'zeynep@forum.cpalius.local',
            'can@forum.cpalius.local', 'ela@forum.cpalius.local', 'burak@forum.cpalius.local',
        ];
        $placeholders = implode(',', array_fill(0, count($emails), '?'));

        $this->addSql(
            "DELETE FROM users WHERE email IN ($placeholders)",
            $emails,
        );
    }

    /** @return array<string, int> */
    private function seedUsers(\DateTimeImmutable $now): array
    {
        /** @var list<array{key: string, email: string, username: string, first: string, last: string, bio: string}> */
        $users = [
            ['key' => 'ayse', 'email' => 'ayse@forum.cpalius.local', 'username' => 'ayse_yilmaz', 'first' => 'Ayşe', 'last' => 'Yılmaz', 'bio' => 'İçerik mimarı, eski Drupal geliştiricisi.'],
            ['key' => 'mehmet', 'email' => 'mehmet@forum.cpalius.local', 'username' => 'mehmet_kaya', 'first' => 'Mehmet', 'last' => 'Kaya', 'bio' => 'Symfony ve CPalius modül geliştiricisi.'],
            ['key' => 'zeynep', 'email' => 'zeynep@forum.cpalius.local', 'username' => 'zeynep_demir', 'first' => 'Zeynep', 'last' => 'Demir', 'bio' => 'Studio editörü, çok dilli site uzmanı.'],
            ['key' => 'can', 'email' => 'can@forum.cpalius.local', 'username' => 'can_ozturk', 'first' => 'Can', 'last' => 'Öztürk', 'bio' => 'Freelance CMF geliştirici.'],
            ['key' => 'ela', 'email' => 'ela@forum.cpalius.local', 'username' => 'ela_arslan', 'first' => 'Ela', 'last' => 'Arslan', 'bio' => 'Tema tasarımcısı ve Twig geliştiricisi.'],
            ['key' => 'burak', 'email' => 'burak@forum.cpalius.local', 'username' => 'burak_sahin', 'first' => 'Burak', 'last' => 'Şahin', 'bio' => 'DevOps, AACP ve performans odaklı.'],
        ];

        $ids = [];
        $created = $now->format('Y-m-d H:i:s');

        foreach ($users as $user) {
            $data = json_encode([
                'first_name' => $user['first'],
                'last_name' => $user['last'],
                'bio' => $user['bio'],
            ], JSON_THROW_ON_ERROR);

            $this->connection->executeStatement(
                'INSERT INTO users (email, username, password, status, roles, data, created_at)
                 VALUES (?, ?, ?, \'active\', \'[]\', ?, ?)',
                [$user['email'], $user['username'], self::PASSWORD_HASH, $data, $created],
            );

            $ids[$user['key']] = (int) $this->connection->lastInsertId();
        }

        return $ids;
    }

    /**
     * @param array{section: string, title: string, slug: string, author: int, body: string, replies: list<array{author: int, body: string}>} $topic
     */
    private function insertTopicWithReplies(array $topic, \DateTimeImmutable $baseTime): void
    {
        $sectionId = $this->connection->fetchOne(
            'SELECT id FROM forum_sections WHERE code = ?',
            [$topic['section']],
        );

        if ($sectionId === false) {
            return;
        }

        $authorName = $this->posterName($topic['author']);
        $postCount = 1 + count($topic['replies']);
        $created = $baseTime->format('Y-m-d H:i:s');

        $this->connection->executeStatement(
            'INSERT INTO forum_topics (section_id, title, slug, mode, state, sticky, view_count, post_count, first_poster_id, first_poster_name, last_poster_id, last_poster_name, preview, created_at, updated_at)
             VALUES (?, ?, ?, 0, 0, 0, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $sectionId,
                $topic['title'],
                $topic['slug'],
                random_int(20, 180),
                $postCount,
                $topic['author'],
                $authorName,
                $topic['author'],
                $authorName,
                mb_substr(strip_tags($topic['body']), 0, 128),
                $created,
                $created,
            ],
        );

        $topicId = (int) $this->connection->lastInsertId();
        $lastPosterId = $topic['author'];
        $lastPosterName = $authorName;
        $lastTime = $baseTime;

        $this->insertPost($topicId, (int) $sectionId, $topic['author'], $authorName, $topic['body'], $created);

        foreach ($topic['replies'] as $offset => $reply) {
            $lastTime = $baseTime->modify(sprintf('+%d hours', $offset + 2));
            $replyTime = $lastTime->format('Y-m-d H:i:s');
            $replyName = $this->posterName($reply['author']);
            $this->insertPost($topicId, (int) $sectionId, $reply['author'], $replyName, $reply['body'], $replyTime);
            $lastPosterId = $reply['author'];
            $lastPosterName = $replyName;
        }

        $this->connection->executeStatement(
            'UPDATE forum_topics SET last_poster_id = ?, last_poster_name = ?, updated_at = ? WHERE id = ?',
            [$lastPosterId, $lastPosterName, $lastTime->format('Y-m-d H:i:s'), $topicId],
        );
    }

    /** @param array<string, int> $userIds */
    private function replyToExistingTopics(array $userIds, \DateTimeImmutable $now): void
    {
        $existing = $this->connection->fetchAllAssociative(
            "SELECT t.id, t.section_id, t.post_count
             FROM forum_topics t
             WHERE t.slug NOT IN (
                'laragon-cpalius-kurulum-rehberi','cok-dilli-site-yapilandirma','node-hibrit-alan-studio',
                'blog-medya-entegrasyon','ilk-ozel-modul-rehberi','tema-assetmapper-migration',
                'aacp-cron-onbellek-rmvp','drupal-den-cpaliusa-tanisma','belediye-portali-vitrin',
                'studio-forum-oneriler','cmf-2026-sohbet','windows-laragon-console-hatalari'
             )
             ORDER BY t.id",
        );

        $authors = array_values($userIds);
        foreach ($existing as $i => $row) {
            $authorId = $authors[$i % count($authors)];
            $replyTime = $now->modify(sprintf('-%d hours', $i + 1))->format('Y-m-d H:i:s');
            $body = $this->body('generic-reply', 130);
            $name = $this->posterName($authorId);

            $this->insertPost((int) $row['id'], (int) $row['section_id'], $authorId, $name, $body, $replyTime);
            $this->connection->executeStatement(
                'UPDATE forum_topics SET post_count = post_count + 1, last_poster_id = ?, last_poster_name = ?, updated_at = ? WHERE id = ?',
                [$authorId, $name, $replyTime, $row['id']],
            );
        }
    }

    private function insertPost(int $topicId, int $sectionId, int $authorId, string $name, string $body, string $createdAt): void
    {
        $this->connection->executeStatement(
            'INSERT INTO forum_posts (topic_id, section_id, author_id, poster_name, body, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?)',
            [$topicId, $sectionId, $authorId, $name, $body, $createdAt, $createdAt],
        );
    }

    private function posterName(int $userId): string
    {
        $row = $this->connection->fetchAssociative('SELECT email, data FROM users WHERE id = ?', [$userId]);
        if ($row === false) {
            return 'Anonim';
        }

        /** @var array<string, mixed> $data */
        $data = json_decode((string) $row['data'], true, 512, JSON_THROW_ON_ERROR);
        $full = trim(((string) ($data['first_name'] ?? '')).' '.((string) ($data['last_name'] ?? '')));

        return $full !== '' ? $full : (string) $row['email'];
    }

    private function body(string $key, int $minWords = 250): string
    {
        $chunks = $this->chunks();
        $parts = $chunks[$key] ?? $chunks['generic'];
        $text = implode(' ', $parts);

        while ($this->wordCount($text) < $minWords) {
            $text .= ' '.$parts[array_key_last($parts)];
        }

        $paragraphs = array_chunk(preg_split('/(?<=[.!?])\s+/u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [$text], 4);
        $html = '';
        foreach ($paragraphs as $paragraph) {
            $html .= '<p>'.htmlspecialchars(implode(' ', $paragraph), ENT_QUOTES, 'UTF-8').'</p>';
        }

        return $html;
    }

    private function wordCount(string $text): int
    {
        $plain = trim(strip_tags($text));

        return count(preg_split('/\s+/u', $plain, -1, PREG_SPLIT_NO_EMPTY) ?: []);
    }

    /** @return array<string, list<string>> */
    private function chunks(): array
    {
        return [
            'kurulum' => [
                'CPalius CMF kurulumuna başlamadan önce PHP 8.2, Composer ve MySQL 8 ortamını hazırlamak kritik.',
                'Laragon kullanıyorsanız proje kökünde cp-core dizinine geçip composer install komutunu çalıştırın.',
                '.env dosyasında DATABASE_URL ve APP_ENV değerlerini doğrulayın; ardından doctrine:migrations:migrate ile şemayı oluşturun.',
                'İlk giriş için mevcut admin kullanıcısını kullanabilir veya console üzerinden yeni kullanıcı tanımlayabilirsiniz.',
                'Kurulum sonrası cache:clear ve asset derleme adımlarını atlamayın; Symfony 7.4 ile AssetMapper varsayılan gelir.',
                'Virtual host tanımında public dizin yerine cp-core/public veya proje yapınıza uygun front controller yolunu gösterin.',
                'Hata alırsanız var/log/dev.log dosyasına bakın; eksik PHP eklentileri sık görülen ilk engeldir.',
                'Forum modülü migration ile otomatik gelir; Studio panelinden bölüm ağacını kontrol ederek yapılandırmaya başlayın.',
            ],
            'yapilandirma' => [
                'Çok dilli CPalius projelerinde locale yönetimi cp_locales tablosu ve çeviri YAML dosyaları üzerinden yürür.',
                'Varsayılan dili AACP veya Studio ayarlarından değiştirebilir; menü ve içerik modülleri locale alanına duyarlıdır.',
                'Forum bölümleri de locale ile filtrelenir; her dil için ayrı slug planı yapmak SEO açısından faydalıdır.',
                'messages+intl-icu.tr.yaml dosyasına yeni anahtarlar eklerken cache temizliği gerekebilir.',
                'URL alias modülü ile blog ve forum sayfalarına özel yollar tanımlayabilirsiniz.',
                'homepage.mode ayarı portal, blog veya forum ana sayfasını belirler; prod ortamında bilinçli seçin.',
                'Ortam değişkenlerinde APP_DEBUG kapalıyken hata mesajları genelleşir; staging ortamında test edin.',
            ],
            'studio' => [
                'Studio paneli CPalius içerik yönetiminin kalbidir; Node entity üzerinden sayfa ve blog içerikleri yönetilir.',
                'Hibrit alan modeli typed kolonlar ile JSON alanları bir arada sunar; sık filtrelenen alanları typed tutmak performans kazandırır.',
                'Flat field indexing sayesinde JSON içindeki belirli anahtarlar sorgulanabilir; bu Drupal Field API ye benzer esneklik sağlar.',
                'Kategori ve etiket taksonomisi blog modülü ile entegre çalışır; Node ilişkilerini Studio formlarından kurabilirsiniz.',
                'Capability tabanlı yetkilendirme editör rolüne forum moderasyonu dahil edilip edilmeyeceğini belirler.',
                'İçerik taslak ve yayın durumları Node status alanı ile kontrol edilir; workflow ihtiyacı modül ile genişletilebilir.',
            ],
            'blog' => [
                'Blog modülü yazılar, kategoriler ve etiketler sunar; tema tarafında cpalius-website şablonları hazır gelir.',
                'Medya kütüphanesi yazı içi görseller için picker sağlar; yüklenen dosyalar Asset entity ile ilişkilendirilir.',
                'Kategori sayfalarında sayfalama ve sidebar düzeni tema CSS ile özelleştirilebilir.',
                'Blog ana sayfa linki menü modülünden yönetilir; locale prefix unutulmamalıdır.',
                'Yorum sistemi planlanan özellikler arasındaysa forum topluluğu geçici geri bildirim kanalı olabilir.',
                'RSS veya JSON feed ihtiyacı custom route ile eklenebilir; Symfony controller pattern takip edin.',
            ],
            'modul' => [
                'Özel modül geliştirmek için cp-content/modules altında dizin oluşturup module.json manifest yazın.',
                'Symfony servis tanımları Resources/config/services.yaml içinde autowire ile kaydedilir.',
                'Capability yaml dosyası admin menü ve izin kontrolü için gereklidir; forum modülünü referans alın.',
                'Hook ve event sistemleri çekirdek davranışı genişletmek için kullanılır; EventSubscriber implement edin.',
                'Modül aktivasyonu AACP modül yönetiminden yapılır; hatalı modül karantina mekanizması ile izole edilebilir.',
                'Test ortamında modül yüklemesini php bin/console cache:clear sonrası doğrulayın.',
            ],
            'tema' => [
                'Tema geliştirmede theme.json manifest, Twig şablonları ve AssetMapper kaynakları birlikte çalışır.',
                'Node.js zorunluluğu olmadan Tailwind ve modern CSS pipeline kullanılabilir; bu CPalius farklarındandır.',
                'Forum şablonları tema içinde forum dizininde yaşar; override için aynı yol yapısını koruyun.',
                'Doctrine migration yazarken hem şema hem seed verisini ayrı migrationlarda tutmak bakımı kolaylaştırır.',
                'Config sync YAML dosyaları ortamlar arası taşınabilirlik sağlar; prod deploy checklist ine ekleyin.',
                'Tema önizlemesi Studio tema yönetiminden yapılır; aktif tema değişikliği cache invalidation gerektirir.',
            ],
            'sistem' => [
                'AACP kök paneli modül arızalarında bile ayakta kalacak şekilde tasarlanmıştır.',
                'Cron yönetimi cp_cron_jobs tablosu üzerinden dinamik job tanımına izin verir.',
                'Performans backendleri Redis ve Memcached RMVP ekranından test edilebilir.',
                'Önbellek stratejisi query ve metadata katmanlarında ayrı düşünülmeli; forum istatistikleri denormalize tutulur.',
                'Güvenlik tarafında capability voter her istekte yetki kontrol eder; N+1 guard geliştirme ortamında aktiftir.',
                'Yedekleme ve karantina logları felaket kurtarma senaryolarında AACP den takip edilir.',
            ],
            'tanisma' => [
                'Merhaba arkadaşlar, uzun yıllar Drupal ile kurumsal projeler geliştirdim.',
                'CPalius CMF ile tanıştığımda Symfony disiplini ve modüler yapı beni hemen cezbetti.',
                'Typo3 benzeri CMF ihtiyacımız vardı ama daha hafif bir çözüm arıyorduk.',
                'Forum topluluğuna katılmaktan mutluyum; kurulum ve modül konularında deneyim paylaşabilirim.',
                'İstanbul dan selamlar, Ayşe.',
            ],
            'vitrin' => [
                'Belediye portalı projemizde CPalius blog, forum ve statik Node sayfalarını bir arada kullandık.',
                'Çok dilli yapı tr ve en locale ile yönetildi; menü modülü header footer ayrımını kolaylaştırdı.',
                'Performans için Redis önbellek ve RMVP ayarları prod da aktif.',
                'Vatandaş geri bildirimi forum modülü üzerinden toplanıyor; moderasyon Studio yetkileri ile sınırlı.',
                'Proje dört ayda canlıya alındı; ekip üç kişiydi.',
            ],
            'geri-bildirim' => [
                'Studio dashboard grafikleri faydalı olmuş; forum istatistikleri de benzer şekilde özetlenebilir.',
                'Bölüm silme onay ekranı güvenlik açısından iyi adım; soft delete düşünülebilir.',
                'Forum profil sayfasında mesaj yazdığı konular sekmesi artık çalışıyor; teşekkürler.',
                'Mobil forum CSS iyileştirmesi sidebar genişliği kadar küçük dokunuşlarla devam edebilir.',
                'Dokümantasyon wiki si forum ile cross-link edilirse arama daha verimli olur.',
            ],
            'serbest' => [
                'CMF ekosisteminde 2026 Symfony 7 LTS yılı gibi görünüyor.',
                'AI kod asistanları forum tartışmalarını hızlandırıyor ama deneyim paylaşımı hâlâ değerli.',
                'WordPress kullanıcıları modüler yapıya geçişte öğrenme eğrisi yaşıyor; sabırlı olmak lazım.',
                'Bu hafta sonu CPalius ile side project başlatacağım; ilerlemeyi vitrinde paylaşırım.',
            ],
            'destek' => [
                'Windows ortamında php bin/console bazen uygulama bulunamadı hatası veriyor.',
                'Laragon terminalinde PHP tam yolunu kullanmak sorunu çözüyor: C laragon bin php php.exe',
                'PATH değişkenine Laragon php eklemek kalıcı çözüm.',
                'Symfony var cache izinleri Windows ta da yazılabilir olmalı.',
                'Aynı hata mysql client için de olabiliyor; Laragon quick app ayarlarını kontrol edin.',
            ],
            'generic-reply' => [
                'Paylaşımınız için teşekkürler, deneyeceğim.',
                'Benzer sorunu yaşamıştım; cache clear sonrası düzelmişti.',
                'Dokümantasyona küçük bir ekleme yapılması faydalı olur.',
                'Prod ortamında da aynı yapılandırmayı kullanıyoruz.',
            ],
            'generic' => [
                'CPalius topluluğu olarak birbirimize yardımcı olmak güzel.',
                'Modüler mimari uzun vadede bakım maliyetini düşürüyor.',
            ],
            'kurulum-reply-1' => ['Laragon da php ext-intl eksikti, ekleyince migration sorunsuz çalıştı.', 'Composer memory limit için COMPOSER_MEMORY_LIMIT=-1 kullandım.'],
            'kurulum-reply-2' => ['Virtual host DocumentRoot u cp-core/public yapmayı unutmayın.', 'İlk kurulumda fixtures yok, migration seed yeterli.'],
            'yapilandirma-reply-1' => ['Locale fallback chain i config den ayarlanabiliyor mu araştırıyorum.'],
            'yapilandirma-reply-2' => ['Forum slug çakışması yaşamamak için bölüm kodlarını kısa tutun.'],
            'studio-reply-1' => ['Node preview modu editörler için zaman kazandırıyor.'],
            'studio-reply-2' => ['JSON alan indeksleme örneği paylaşabilir misiniz?'],
            'blog-reply-1' => ['Medya picker blog editöründe lazy load ile hızlanabilir.'],
            'blog-reply-2' => ['Kategori sidebar genişliği biraz dar geldi bana da.'],
            'modul-reply-1' => ['module.json version alanı semver mi takip ediyor?'],
            'modul-reply-2' => ['Hook priority sırası dokümante edilmeli.'],
            'tema-reply-1' => ['AssetMapper prod build pipeline ını merak ediyorum.'],
            'tema-reply-2' => ['Forum CSS değişkenleri tema.json dan okunabilir mi?'],
            'sistem-reply-1' => ['Cron job logları AACP den görünüyor, kullanışlı.'],
            'sistem-reply-2' => ['Redis test butonu hata mesajını net gösteriyor.'],
            'tanisma-reply-1' => ['Hoş geldin Ayşe, Drupal geçmişi burada avantaj.'],
            'tanisma-reply-2' => ['Ben Typo3 ten geliyorum, benzer hissettirdi.'],
            'vitrin-reply-1' => ['Belediye projesi etkileyici, tebrikler.'],
            'vitrin-reply-2' => ['Forum geri bildirim akışını merak ediyorum.'],
            'geri-bildirim-reply-1' => ['Soft delete forum bölümleri için destekliyorum.'],
            'geri-bildirim-reply-2' => ['Mobil CSS iyileştirmesi yakında gelir umarım.'],
            'serbest-reply-1' => ['AI ile migration yazmak riskli ama hızlı.'],
            'serbest-reply-2' => ['Side project için hangi modülleri kullanacaksın?'],
            'destek-reply-1' => ['PowerShell de & operatörü ile tam yol işe yarar.'],
            'destek-reply-2' => ['Laragon php 8.3 e geçince düzeldi bende.'],
        ];
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

        $this->connection->executeStatement(<<<'SQL'
            UPDATE forum_topics t
            SET post_count = (SELECT COUNT(*) FROM forum_posts p WHERE p.topic_id = t.id)
            SQL);
    }
}
