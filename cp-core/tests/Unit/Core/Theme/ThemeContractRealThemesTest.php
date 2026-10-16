<?php

declare(strict_types=1);

namespace App\Tests\Unit\Core\Theme;

use App\Core\Settings\SettingsRegistry;
use App\Core\Theme\ThemeDiscovery;
use App\Core\Theme\ThemePackageContract;
use App\Core\Theme\ThemeRegistry;
use App\Entity\Setting;
use App\Repository\SettingRepository;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

/**
 * Contract + discovery + fallback areas against the real cp-content/themes directory.
 */
final class ThemeContractRealThemesTest extends TestCase
{
    private string $themesDir;

    protected function setUp(): void
    {
        $this->themesDir = \dirname(__DIR__, 5).'/cp-content/themes';
    }

    public function testShippedThemesSatisfyTheContract(): void
    {
        $themes = ThemeDiscovery::scan($this->themesDir);

        foreach ([ThemeRegistry::FALLBACK_THEME, 'githeme'] as $dirName) {
            self::assertArrayHasKey($dirName, $themes);
            self::assertSame([], ThemePackageContract::problems($this->themesDir.'/'.$dirName), $dirName);
        }
    }

    public function testGithemeFallsBackToDefaultThemeForForum(): void
    {
        $registry = new ThemeRegistry(
            $this->createStub(SettingsRegistry::class),
            $this->createStub(SettingRepository::class),
            $this->createStub(EntityManagerInterface::class),
            $this->themesDir,
            \dirname($this->themesDir, 2),
        );

        $githeme = $registry->get('githeme');
        self::assertNotNull($githeme);
        self::assertArrayHasKey('forum', $registry->fallbackAreas($githeme));
    }
}
