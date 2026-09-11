<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * T2.2: per-(bundle, view mode, field) display overrides (visibility/weight/
 * label). Absence of a row means "use the field's own defaults" — no data
 * migration needed for existing content.
 */
final class Version20260911200000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'View modes: cp_entity_displays.';
    }

    public function up(Schema $schema): void
    {
        $sm = $this->connection->createSchemaManager();
        if ($sm->tablesExist(['cp_entity_displays'])) {
            return;
        }

        $this->addSql('CREATE TABLE cp_entity_displays (
            id INT AUTO_INCREMENT NOT NULL,
            bundle VARCHAR(64) NOT NULL,
            view_mode VARCHAR(32) NOT NULL,
            field_name VARCHAR(64) NOT NULL,
            visible TINYINT(1) NOT NULL,
            weight INT NOT NULL,
            label_display VARCHAR(10) NOT NULL,
            INDEX idx_display_bundle_viewmode (bundle, view_mode),
            UNIQUE INDEX uniq_display_bundle_viewmode_field (bundle, view_mode, field_name),
            PRIMARY KEY(id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
    }

    public function down(Schema $schema): void
    {
        $sm = $this->connection->createSchemaManager();
        if ($sm->tablesExist(['cp_entity_displays'])) {
            $this->addSql('DROP TABLE cp_entity_displays');
        }
    }
}
