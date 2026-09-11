<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Core taxonomy: vocabularies (structure, config-exportable) + terms (content,
 * fieldable via bundle = vocabulary machine_name, translatable via
 * translation_group_id). Category/Tag are untouched.
 */
final class Version20260911120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Core taxonomy: cp_vocabularies + cp_terms.';
    }

    public function up(Schema $schema): void
    {
        $sm = $this->connection->createSchemaManager();

        if (!$sm->tablesExist(['cp_vocabularies'])) {
            $this->addSql('CREATE TABLE cp_vocabularies (
                id INT AUTO_INCREMENT NOT NULL,
                machine_name VARCHAR(64) NOT NULL,
                label VARCHAR(191) NOT NULL,
                description VARCHAR(500) DEFAULT NULL,
                hierarchical TINYINT(1) NOT NULL,
                weight INT NOT NULL,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                UNIQUE INDEX uniq_vocabulary_machine_name (machine_name),
                PRIMARY KEY(id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        }

        if (!$sm->tablesExist(['cp_terms'])) {
            $this->addSql('CREATE TABLE cp_terms (
                id INT AUTO_INCREMENT NOT NULL,
                vocabulary_id INT NOT NULL,
                parent_id INT DEFAULT NULL,
                name VARCHAR(255) NOT NULL,
                slug VARCHAR(255) NOT NULL,
                locale VARCHAR(5) NOT NULL,
                weight INT NOT NULL,
                data JSON NOT NULL,
                translation_group_id CHAR(36) DEFAULT NULL COMMENT \'(DC2Type:uuid)\',
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                INDEX idx_term_vocab_weight (vocabulary_id, weight),
                INDEX idx_term_vocab_parent (vocabulary_id, parent_id),
                INDEX idx_term_locale (locale),
                UNIQUE INDEX uniq_term_vocab_slug_locale (vocabulary_id, slug, locale),
                UNIQUE INDEX uniq_term_translation_group_locale (translation_group_id, locale),
                PRIMARY KEY(id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

            $this->addSql('ALTER TABLE cp_terms
                ADD CONSTRAINT fk_cp_terms_vocabulary FOREIGN KEY (vocabulary_id) REFERENCES cp_vocabularies (id) ON DELETE CASCADE');
            $this->addSql('ALTER TABLE cp_terms
                ADD CONSTRAINT fk_cp_terms_parent FOREIGN KEY (parent_id) REFERENCES cp_terms (id) ON DELETE SET NULL');
        }
    }

    public function down(Schema $schema): void
    {
        $sm = $this->connection->createSchemaManager();

        if ($sm->tablesExist(['cp_terms'])) {
            $this->addSql('DROP TABLE cp_terms');
        }
        if ($sm->tablesExist(['cp_vocabularies'])) {
            $this->addSql('DROP TABLE cp_vocabularies');
        }
    }
}
