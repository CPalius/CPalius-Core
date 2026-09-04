<?php

declare(strict_types=1);

/*
 * CPalius CMF — PHPUnit önyükleme (FAZ 2)
 *
 * Symfony'nin standart tests/bootstrap.php dosyasının CPalius'un
 * "Pristine Root" düzenine uyarlanmış hâli: autoloader cp-includes/vendor
 * altında, .env dosyaları ise proje KÖKÜNDEDİR (cp-core'da değil).
 */

use Symfony\Component\Dotenv\Dotenv;

// cp-core/tests/bootstrap.php -> cp-core/tests -> cp-core -> proje kökü
$projectDir = \dirname(__DIR__, 2);

require $projectDir.'/cp-includes/vendor/autoload.php';

/*
 * .env yükleme sırası: .env -> .env.test -> .env.test.local
 *
 * bootEnv() BİLİNÇLİ olarak kullanılır (loadEnv() yerine): .env.local.php
 * derlenmiş önbelleği varsa onu kullanır ve APP_ENV/APP_DEBUG
 * çözümlemesini Symfony'nin kendi kurallarıyla yapar — testin ortam
 * yükleme davranışı, gerçek uygulamanınkiyle BİREBİR aynı olmalıdır,
 * yoksa test ettiğimiz şey üretimde koşan şey olmaz.
 *
 * phpunit.xml.dist zaten APP_ENV=test'i force="true" ile $_SERVER'a
 * yazar; bootEnv() bunu görür ve .env.test dosyasını yükler.
 */
if (method_exists(Dotenv::class, 'bootEnv')) {
    (new Dotenv())->bootEnv($projectDir.'/.env');
}

/*
 * Test veritabanı: her PHPUnit koşusu TEMİZ bir SQLite dosyasıyla başlar.
 *
 * Neden burada ve neden dosya silerek:
 *
 *   - Şemayı her test sınıfında yeniden kurmak yavaştır; burada tek sefer
 *     sıfırlamak yeterlidir (şemanın KENDİSİNİ kuran kod, ona ihtiyaç
 *     duyan integration testinin kendi setUp'ındadır — unit testleri
 *     veritabanına hiç dokunmaz ve bu maliyeti ödememelidir).
 *
 *   - Önceki koşudan kalan bir test.db, "yeşil ama yanlış" testler
 *     üretebilir: silinmiş bir migration'ın tablosu hâlâ orada durduğu
 *     için gerçek bir şema regresyonu görünmez kalır.
 *
 * Üretim veritabanına yanlışlıkla dokunma riski yoktur: yol .env.test
 * içinde sabittir ve yalnızca cp-core/var/test.db dosyasını hedefler.
 */
$testDatabase = $projectDir.'/cp-core/var/test.db';

if (is_file($testDatabase)) {
    @unlink($testDatabase);
}

$varDir = $projectDir.'/cp-core/var';
if (!is_dir($varDir)) {
    @mkdir($varDir, 0775, true);
}

/*
 * Karantina logu da sıfırlanır: ModuleIsolationTest bu dosyanın İÇERİĞİNİ
 * doğrular ("bozuk modül gerçekten karantinaya yazıldı mı?"). Önceki
 * koşudan kalan satırlar, testi kendi yazdığı satır olmadan da
 * geçirebilirdi — yani test hiçbir şey kanıtlamazdı.
 */
$quarantineLog = $projectDir.'/cp-core/var/log/module_quarantine.log';

if (is_file($quarantineLog)) {
    @unlink($quarantineLog);
}
