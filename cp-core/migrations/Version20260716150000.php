<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * User management: adds optional username column to users (login by email or username).
 */
final class Version20260716150000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Adds a nullable/unique "username" column to users (login via email or username).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE users ADD username VARCHAR(180) DEFAULT NULL');
        $this->addSql('CREATE UNIQUE INDEX uniq_user_username ON users (username)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX uniq_user_username ON users');
        $this->addSql('ALTER TABLE users DROP username');
    }
}
