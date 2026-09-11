<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260715205349 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Frontend menu management: menus + menu_items tables (node_id intentionally not an FK; loose reference).';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE menu_items (id INT AUTO_INCREMENT NOT NULL, label VARCHAR(255) NOT NULL, locale VARCHAR(5) NOT NULL, url VARCHAR(255) DEFAULT NULL, node_id INT DEFAULT NULL, sort_order INT NOT NULL, open_in_new_tab TINYINT NOT NULL, menu_id INT NOT NULL, parent_id INT DEFAULT NULL, INDEX IDX_70B2CA2ACCD7E912 (menu_id), INDEX IDX_70B2CA2A727ACA70 (parent_id), INDEX idx_menu_item_parent (menu_id, parent_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE menus (id INT AUTO_INCREMENT NOT NULL, name VARCHAR(255) NOT NULL, identifier VARCHAR(100) NOT NULL, UNIQUE INDEX uniq_menu_identifier (identifier), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE menu_items ADD CONSTRAINT FK_70B2CA2ACCD7E912 FOREIGN KEY (menu_id) REFERENCES menus (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE menu_items ADD CONSTRAINT FK_70B2CA2A727ACA70 FOREIGN KEY (parent_id) REFERENCES menu_items (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE menu_items DROP FOREIGN KEY FK_70B2CA2ACCD7E912');
        $this->addSql('ALTER TABLE menu_items DROP FOREIGN KEY FK_70B2CA2A727ACA70');
        $this->addSql('DROP TABLE menu_items');
        $this->addSql('DROP TABLE menus');
    }
}
