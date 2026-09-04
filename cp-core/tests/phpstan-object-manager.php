<?php

declare(strict_types=1);

/*
 * PHPStan Doctrine eklentisi için EntityManager yükleyicisi.
 *
 * Eklenti bu dosyadan bir ObjectManager alır ve entity metadata'sını
 * okuyarak repository metotlarının GERÇEK dönüş tiplerini çözer:
 * NodeRepository::find() -> ?Node, findAll() -> list<Node>, DQL
 * alanlarının var olup olmadığı vb. Bu olmadan seviye 6'da her
 * repository çağrısı "mixed" döner.
 *
 * Ortam "dev": phpstan.neon'daki containerXmlPath ve konsol yükleyicisi
 * ile aynı ortam olmalıdır (bkz. phpstan-console-loader.php).
 *
 * Bu dosya veritabanına BAĞLANMAZ — Doctrine yalnızca metadata sürücüsünü
 * (attribute okuyucu) kullanır, bağlantı ilk sorguda kurulur ve burada
 * hiç sorgu çalıştırılmaz. Dolayısıyla PHPStan çalışan bir veritabanı
 * olmadan da analiz yapabilir.
 */

use App\Kernel;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Dotenv\Dotenv;

$projectDir = \dirname(__DIR__, 2);

require $projectDir.'/cp-includes/vendor/autoload.php';

(new Dotenv())->bootEnv($projectDir.'/.env');

$kernel = new Kernel('dev', true);
$kernel->boot();

return $kernel->getContainer()->get('doctrine')->getManager();
