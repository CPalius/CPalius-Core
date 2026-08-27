<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Kullanıcı Yönetimi: users tablosuna opsiyonel "username" kolonu ekler
 * (bkz. App\Entity\User::$username, App\Core\Security\CpUserProvider) —
 * e-postanın yanı sıra kullanıcı adıyla giriş desteği için.
 */
final class Version20260716150000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'users tablosuna nullable/unique "username" kolonu ekler (e-posta veya kullanıcı adıyla giriş desteği).';
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
