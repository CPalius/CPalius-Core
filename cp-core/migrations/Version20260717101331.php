<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * cp_performance_backend_status tablosunu oluşturur ve 4 önbellek
 * backend'i (redis/memcached/varnish/pagespeed) için varsayılan (devre
 * dışı, test edilmemiş) satırları seed eder — bkz. App\Entity\
 * PerformanceBackendStatus. Tüm satırların baştan var olması,
 * PerformanceBackendStatusRepository::findOneByBackendId()'nin migrate
 * edilmiş her sistemde null yerine her zaman bir satır dönebileceğini
 * garanti eder.
 */
final class Version20260717101331 extends AbstractMigration
{
    public function getDescription(): string
    {
        return "cp_performance_backend_status tablosunu oluşturur ve 4 önbellek backend'i için varsayılan (devre dışı, test edilmemiş) satırları seed eder.";
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE cp_performance_backend_status (
                id INT AUTO_INCREMENT NOT NULL,
                backend_id VARCHAR(32) NOT NULL,
                is_enabled TINYINT(1) NOT NULL,
                last_test_success TINYINT(1) NOT NULL,
                last_test_status VARCHAR(32) DEFAULT NULL,
                last_test_message VARCHAR(255) DEFAULT NULL,
                last_tested_at DATETIME DEFAULT NULL,
                UNIQUE INDEX uniq_performance_backend_id (backend_id),
                PRIMARY KEY(id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
            SQL);

        foreach (['redis', 'memcached', 'varnish', 'pagespeed'] as $backendId) {
            $this->addSql(
                'INSERT INTO cp_performance_backend_status (backend_id, is_enabled, last_test_success) VALUES (?, 0, 0)',
                [$backendId],
            );
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE cp_performance_backend_status');
    }
}
