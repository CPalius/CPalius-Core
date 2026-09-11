<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Forum structure translations reuse the same code per locale, and each
 * topic stores the language it was written in (not translated).
 */
final class Version20260906130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Forum sections UNIQUE(code, locale); forum_topics.locale backfilled from the board.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE forum_sections DROP INDEX uniq_forum_section_code');
        $this->addSql('CREATE UNIQUE INDEX uniq_forum_section_code_locale ON forum_sections (code, locale)');

        $this->addSql("ALTER TABLE forum_topics ADD locale VARCHAR(5) NOT NULL DEFAULT 'tr'");
        $this->addSql('UPDATE forum_topics t INNER JOIN forum_sections s ON t.section_id = s.id SET t.locale = s.locale');
        $this->addSql('CREATE INDEX idx_forum_topic_locale ON forum_topics (locale)');
        $this->addSql('ALTER TABLE forum_topics MODIFY locale VARCHAR(5) NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_forum_topic_locale ON forum_topics');
        $this->addSql('ALTER TABLE forum_topics DROP locale');

        $this->addSql('DROP INDEX uniq_forum_section_code_locale ON forum_sections');
        $this->addSql('CREATE UNIQUE INDEX uniq_forum_section_code ON forum_sections (code)');
    }
}
