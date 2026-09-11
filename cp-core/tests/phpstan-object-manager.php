<?php

declare(strict_types=1);

/*
 * EntityManager loader for PHPStan Doctrine extension (resolves repository return types from metadata).
 * Uses dev kernel; connects to DB only on first query — not during PHPStan bootstrap.
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
