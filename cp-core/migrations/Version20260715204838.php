<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260715204838 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Tag entity plus Node many-to-many category/tag relations (node_category, node_tag join tables).';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE node_category (node_id INT NOT NULL, category_id INT NOT NULL, INDEX IDX_B8E10194460D9FD7 (node_id), INDEX IDX_B8E1019412469DE2 (category_id), PRIMARY KEY (node_id, category_id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE node_tag (node_id INT NOT NULL, tag_id INT NOT NULL, INDEX IDX_70AC95F8460D9FD7 (node_id), INDEX IDX_70AC95F8BAD26311 (tag_id), PRIMARY KEY (node_id, tag_id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE tags (id INT AUTO_INCREMENT NOT NULL, name VARCHAR(255) NOT NULL, slug VARCHAR(255) NOT NULL, locale VARCHAR(5) NOT NULL, INDEX idx_tag_locale (locale), UNIQUE INDEX uniq_tag_slug_locale (slug, locale), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE node_category ADD CONSTRAINT FK_B8E10194460D9FD7 FOREIGN KEY (node_id) REFERENCES nodes (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE node_category ADD CONSTRAINT FK_B8E1019412469DE2 FOREIGN KEY (category_id) REFERENCES categories (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE node_tag ADD CONSTRAINT FK_70AC95F8460D9FD7 FOREIGN KEY (node_id) REFERENCES nodes (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE node_tag ADD CONSTRAINT FK_70AC95F8BAD26311 FOREIGN KEY (tag_id) REFERENCES tags (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE node_category DROP FOREIGN KEY FK_B8E10194460D9FD7');
        $this->addSql('ALTER TABLE node_category DROP FOREIGN KEY FK_B8E1019412469DE2');
        $this->addSql('ALTER TABLE node_tag DROP FOREIGN KEY FK_70AC95F8460D9FD7');
        $this->addSql('ALTER TABLE node_tag DROP FOREIGN KEY FK_70AC95F8BAD26311');
        $this->addSql('DROP TABLE node_category');
        $this->addSql('DROP TABLE node_tag');
        $this->addSql('DROP TABLE tags');
    }
}
