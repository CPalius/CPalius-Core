<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Replaces last_test_message with last_test_message_key + last_test_message_params (translation keys, not stored text).
 * Existing free-text values are intentionally dropped; rows reset to untested until probbed again.
 */
final class Version20260717111226 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Replaces cp_performance_backend_status.last_test_message with last_test_message_key + last_test_message_params (JSON).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE cp_performance_backend_status ADD last_test_message_key VARCHAR(191) DEFAULT NULL, ADD last_test_message_params JSON NOT NULL');
        $this->addSql("UPDATE cp_performance_backend_status SET last_test_message_params = '{}'");
        $this->addSql('ALTER TABLE cp_performance_backend_status DROP last_test_message');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE cp_performance_backend_status ADD last_test_message VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE cp_performance_backend_status DROP last_test_message_key, DROP last_test_message_params');
    }
}
