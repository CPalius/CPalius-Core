<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260827210000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Forum post dislike table.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE forum_post_dislikes (
            id INT AUTO_INCREMENT NOT NULL,
            post_id INT NOT NULL,
            user_id INT NOT NULL,
            created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\',
            UNIQUE INDEX uniq_forum_post_dislike (post_id, user_id),
            INDEX IDX_FPD_POST (post_id),
            INDEX IDX_FPD_USER (user_id),
            PRIMARY KEY(id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE forum_post_dislikes ADD CONSTRAINT FK_FPD_POST FOREIGN KEY (post_id) REFERENCES forum_posts (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE forum_post_dislikes ADD CONSTRAINT FK_FPD_USER FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE forum_post_dislikes DROP FOREIGN KEY FK_FPD_POST');
        $this->addSql('ALTER TABLE forum_post_dislikes DROP FOREIGN KEY FK_FPD_USER');
        $this->addSql('DROP TABLE forum_post_dislikes');
    }
}
