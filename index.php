<?php

declare(strict_types=1);

/**
 * Shared-hosting front controller. When the document root is the project
 * directory (a typical panel upload) this file is what the web server runs.
 * Symfony Runtime loads it a second time and expects the closure returned
 * by public/index.php, so the require must be returned.
 */
return require __DIR__.'/public/index.php';
