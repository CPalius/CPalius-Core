<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Roadmap entries join the same translation-group contract as categories/tags:
 * one row per locale, siblings share translation_group_id.
 */
final class Version20260906120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Adds translation_group_id and UNIQUE(translation_group_id, locale) on roadmap_entries.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE roadmap_entries ADD translation_group_id BINARY(16) DEFAULT NULL COMMENT '(DC2Type:uuid)'");
        $this->addSql('CREATE UNIQUE INDEX uniq_roadmap_translation_group_locale ON roadmap_entries (translation_group_id, locale)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX uniq_roadmap_translation_group_locale ON roadmap_entries');
        $this->addSql('ALTER TABLE roadmap_entries DROP translation_group_id');
    }
}
