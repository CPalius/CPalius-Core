<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260714201100 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE node_field_index (id INT AUTO_INCREMENT NOT NULL, field_name VARCHAR(100) NOT NULL, value_string VARCHAR(255) DEFAULT NULL, value_int INT DEFAULT NULL, value_decimal NUMERIC(10, 2) DEFAULT NULL, value_datetime DATETIME DEFAULT NULL, node_id INT NOT NULL, INDEX IDX_EBDF5A13460D9FD7 (node_id), INDEX idx_nfi_field_string (field_name, value_string), INDEX idx_nfi_field_int (field_name, value_int), INDEX idx_nfi_field_decimal (field_name, value_decimal), INDEX idx_nfi_field_datetime (field_name, value_datetime), UNIQUE INDEX uniq_node_field_index (node_id, field_name), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE node_field_index ADD CONSTRAINT FK_EBDF5A13460D9FD7 FOREIGN KEY (node_id) REFERENCES nodes (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE node_field_index DROP FOREIGN KEY FK_EBDF5A13460D9FD7');
        $this->addSql('DROP TABLE node_field_index');
    }
}
