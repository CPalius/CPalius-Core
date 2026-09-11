<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Blog comments table for member/guest discussion with Studio moderation.
 */
final class Version20260907120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Creates blog_comments for post discussion and Studio moderation.';
    }

    public function up(Schema $schema): void
    {
        $schemaManager = $this->connection->createSchemaManager();
        if ($schemaManager->tablesExist(['blog_comments'])) {
            return;
        }

        $this->addSql('CREATE TABLE blog_comments (
            id INT AUTO_INCREMENT NOT NULL,
            node_id INT NOT NULL,
            parent_id INT DEFAULT NULL,
            author_id INT DEFAULT NULL,
            guest_name VARCHAR(100) DEFAULT NULL,
            guest_email VARCHAR(180) DEFAULT NULL,
            body LONGTEXT NOT NULL,
            status VARCHAR(20) NOT NULL,
            poster_ip VARCHAR(64) DEFAULT NULL,
            created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\',
            updated_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\',
            INDEX idx_blog_comment_node (node_id),
            INDEX idx_blog_comment_status (status),
            INDEX idx_blog_comment_parent (parent_id),
            INDEX idx_blog_comment_author (author_id),
            INDEX idx_blog_comment_node_status (node_id, status),
            PRIMARY KEY(id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE blog_comments ADD CONSTRAINT FK_BLOG_COMMENT_NODE FOREIGN KEY (node_id) REFERENCES nodes (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE blog_comments ADD CONSTRAINT FK_BLOG_COMMENT_PARENT FOREIGN KEY (parent_id) REFERENCES blog_comments (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE blog_comments ADD CONSTRAINT FK_BLOG_COMMENT_AUTHOR FOREIGN KEY (author_id) REFERENCES users (id) ON DELETE SET NULL');
    }

    public function down(Schema $schema): void
    {
        $schemaManager = $this->connection->createSchemaManager();
        if (!$schemaManager->tablesExist(['blog_comments'])) {
            return;
        }

        $this->addSql('ALTER TABLE blog_comments DROP FOREIGN KEY FK_BLOG_COMMENT_NODE');
        $this->addSql('ALTER TABLE blog_comments DROP FOREIGN KEY FK_BLOG_COMMENT_PARENT');
        $this->addSql('ALTER TABLE blog_comments DROP FOREIGN KEY FK_BLOG_COMMENT_AUTHOR');
        $this->addSql('DROP TABLE blog_comments');
    }
}
