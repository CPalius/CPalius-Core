<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Core\Config\ConfigManager;
use App\Tests\Support\IntegrationTestCase;
use PHPUnit\Framework\Attributes\CoversNothing;

/** The update step must not re-report configuration that is already in sync. */
#[CoversNothing]
final class ConfigImportIdempotentTest extends IntegrationTestCase
{
    public function testSecondImportAppliesNothing(): void
    {
        $this->container();
        /** @var ConfigManager $config */
        $config = $this->container()->get(ConfigManager::class);

        $config->import();

        self::assertSame([], $config->import(), 'documents already in sync are not imported again');
    }
}
