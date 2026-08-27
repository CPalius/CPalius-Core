<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * cp_performance_backend_status.last_test_message (hazır, dil-bağımlı bir
 * metin) sütununu last_test_message_key + last_test_message_params (JSON)
 * çiftine dönüştürür — bkz. App\Entity\PerformanceBackendStatus docblock'u:
 * DB'ye artık asla çevrilmiş metin YAZILMAZ, sadece bir çeviri anahtarı ve
 * ICU parametreleri saklanır, görüntüleme anında |trans uygulanır. Bu sayede
 * AACP dil değiştirildiğinde daha önce kaydedilmiş bir test sonucu bile
 * doğru dilde görüntülenir.
 *
 * Var olan satırlardaki eski serbest-metin last_test_message DEĞERİ
 * kasıtlı olarak KORUNMAZ (geri dönüşü olmayan bir kayıp gibi görünse de):
 * o metin zaten hangi çeviri anahtarına karşılık geldiği bilinmeyen, rastgele
 * bir string'ti (migration zamanında yalnızca 4 seed satırı vardı, hepsi
 * "henüz test edilmedi" durumundaydı — bkz. Version20260717101331). Var olan
 * satırlar bu migration sonrası last_test_message_key=NULL, params=[] ile
 * "henüz test edilmedi" görünümüne döner; kullanıcı Test'e tekrar basınca
 * doğru anahtarla yeniden doldurulur.
 */
final class Version20260717111226 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'cp_performance_backend_status.last_test_message sütununu last_test_message_key + last_test_message_params (JSON) çiftine dönüştürür.';
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
