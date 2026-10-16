<?php

declare(strict_types=1);

use App\Core\Install\InstallGate;
use App\Core\Version\Service\AtomicUpdateInstaller;
use App\Kernel;

$projectDir = dirname(__DIR__);

require_once $projectDir.'/cp-core/src/Core/Install/InstallGate.php';

if (!InstallGate::shouldBootApplication($projectDir)) {
    $wizard = InstallGate::wizardFile($projectDir);
    if (is_file($wizard)) {
        require $wizard;
        exit;
    }

    http_response_code(503);
    header('Content-Type: text/plain; charset=UTF-8');
    echo "CPalius is not installed, and the installer files are missing.\n";
    exit;
}

// Answers 503 during an update and undoes one whose process died, before any framework code loads.
require_once $projectDir.'/cp-core/src/Core/Version/Service/AtomicUpdateInstaller.php';
(new AtomicUpdateInstaller($projectDir))->guardRequest();

require_once $projectDir.'/cp-includes/vendor/autoload_runtime.php';

return static function (array $context) {
    return new Kernel($context['APP_ENV'], (bool) $context['APP_DEBUG']);
};
