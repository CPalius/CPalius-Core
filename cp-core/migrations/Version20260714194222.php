<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Bu migration dosyası, ilk sürümü kaybolduktan sonra veritabanının
 * doctrine_migration_versions kaydıyla tutarlı kalması için yeniden
 * oluşturulmuştur. Veritabanı zaten bu migration'ın hedeflediği şemaya
 * (categories/nodes tabloları, ilk hali) sahip olduğundan up()/down()
 * bilinçli olarak boş bırakılmıştır — gerçek şema değişiklikleri bir
 * sonraki migration'da (Version20260714195853) uygulanır.
 */
final class Version20260714194222 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'categories ve nodes tablolarının ilk oluşturulması (dosyası kaybolduğu için yeniden oluşturuldu, no-op).';
    }

    public function up(Schema $schema): void
    {
    }

    public function down(Schema $schema): void
    {
    }
}
