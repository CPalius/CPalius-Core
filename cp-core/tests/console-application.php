<?php

declare(strict_types=1);

/*
 * Console Application loader for the PHPStan Symfony extension (resolves
 * Command argument/option types). Mirrors phpstan-object-manager.php: the
 * dev kernel is used deliberately, so the analysis reads the same container
 * that "symfony.containerXmlPath" points at — a dev container paired with a
 * test entity manager would be an inconsistent picture of the application.
 */

use App\Kernel;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Dotenv\Dotenv;

$projectDir = \dirname(__DIR__, 2);

require $projectDir.'/cp-includes/vendor/autoload.php';

(new Dotenv())->bootEnv($projectDir.'/.env');

return new Application(new Kernel('dev', true));
