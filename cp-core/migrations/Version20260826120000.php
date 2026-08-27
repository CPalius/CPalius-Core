<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use Symfony\Component\String\Slugger\AsciiSlugger;

/**
 * Forum modülü tamamlama seti: bölüm ikonu, konu slug'ı ve mesaj
 * raporlama/moderasyon kuyruğu (forum_post_reports — Cotonti'de doğrudan
 * karşılığı yok, AACP moderasyon masası için yeni bir tablo).
 */
final class Version20260826120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Forum bölüm ikonu, konu slug alanı ve mesaj raporlama tablosunu ekler.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE forum_sections ADD icon VARCHAR(64) DEFAULT NULL');
        $this->addSql('ALTER TABLE forum_topics ADD slug VARCHAR(190) DEFAULT NULL');
        $this->addSql('CREATE INDEX idx_forum_topic_slug ON forum_topics (slug)');

        $this->addSql(<<<'SQL'
            CREATE TABLE forum_post_reports (
                id INT AUTO_INCREMENT NOT NULL,
                post_id INT NOT NULL,
                reporter_id INT DEFAULT NULL,
                reporter_name VARCHAR(100) DEFAULT NULL,
                reason LONGTEXT NOT NULL,
                status SMALLINT NOT NULL DEFAULT 0,
                resolved_by_id INT DEFAULT NULL,
                created_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                resolved_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)',
                INDEX idx_forum_post_report_status (status),
                INDEX IDX_FORUM_POST_REPORT_POST (post_id),
                INDEX IDX_FORUM_POST_REPORT_REPORTER (reporter_id),
                INDEX IDX_FORUM_POST_REPORT_RESOLVED_BY (resolved_by_id),
                PRIMARY KEY(id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
            SQL);

        $this->addSql('ALTER TABLE forum_post_reports ADD CONSTRAINT FK_FORUM_POST_REPORT_POST FOREIGN KEY (post_id) REFERENCES forum_posts (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE forum_post_reports ADD CONSTRAINT FK_FORUM_POST_REPORT_REPORTER FOREIGN KEY (reporter_id) REFERENCES users (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE forum_post_reports ADD CONSTRAINT FK_FORUM_POST_REPORT_RESOLVED_BY FOREIGN KEY (resolved_by_id) REFERENCES users (id) ON DELETE SET NULL');
    }

    /**
     * Mevcut konulara geriye dönük slug üretir. MySQL sürümü ne olursa olsun
     * çalışması için (REGEXP_REPLACE MySQL 8+ ister) SQL yerine uygulamanın
     * kendi AsciiSlugger'ı PHP tarafında kullanılır.
     */
    public function postUp(Schema $schema): void
    {
        $slugger = new AsciiSlugger();
        $rows = $this->connection->fetchAllAssociative('SELECT id, title FROM forum_topics WHERE slug IS NULL OR slug = \'\'');

        foreach ($rows as $row) {
            $slug = mb_strtolower($slugger->slug((string) $row['title'])->toString());
            $this->connection->executeStatement(
                'UPDATE forum_topics SET slug = ? WHERE id = ?',
                [$slug !== '' ? $slug : 'konu', $row['id']],
            );
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE forum_post_reports DROP FOREIGN KEY FK_FORUM_POST_REPORT_RESOLVED_BY');
        $this->addSql('ALTER TABLE forum_post_reports DROP FOREIGN KEY FK_FORUM_POST_REPORT_REPORTER');
        $this->addSql('ALTER TABLE forum_post_reports DROP FOREIGN KEY FK_FORUM_POST_REPORT_POST');
        $this->addSql('DROP TABLE forum_post_reports');
        $this->addSql('DROP INDEX idx_forum_topic_slug ON forum_topics');
        $this->addSql('ALTER TABLE forum_topics DROP slug');
        $this->addSql('ALTER TABLE forum_sections DROP icon');
    }
}
