<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Creates cp_locales as the single source of truth for enabled languages; seeds default locale from settings or 'tr'.
 */
final class Version20260716220000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Creates the cp_locales table and seeds it from the existing core.default_locale setting.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE cp_locales (
                id INT AUTO_INCREMENT NOT NULL,
                code VARCHAR(5) NOT NULL,
                name VARCHAR(100) NOT NULL,
                native_name VARCHAR(100) NOT NULL,
                is_active TINYINT(1) NOT NULL,
                is_default TINYINT(1) NOT NULL,
                sort_order INT NOT NULL,
                UNIQUE INDEX uniq_locale_code (code),
                PRIMARY KEY(id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
            SQL);

        $defaultCode = (string) ($this->connection->fetchOne(
            "SELECT setting_value FROM cp_settings WHERE setting_key = 'core.default_locale'",
        ) ?: 'tr');

        $names = [
            'tr' => ['Türkçe', 'Türkçe'],
            'en' => ['English', 'English'],
            'es' => ['Español', 'Español'],
        ];
        [$name, $nativeName] = $names[$defaultCode] ?? [$defaultCode, $defaultCode];

        $this->addSql(
            'INSERT INTO cp_locales (code, name, native_name, is_active, is_default, sort_order) VALUES (?, ?, ?, 1, 1, 0)',
            [$defaultCode, $name, $nativeName],
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE cp_locales');
    }
}
