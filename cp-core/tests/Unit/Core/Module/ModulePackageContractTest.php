<?php

declare(strict_types=1);

namespace App\Tests\Unit\Core\Module;

use App\Core\Module\ModuleContributionReader;
use App\Core\Module\ModuleImportmapLoader;
use App\Core\Module\ModulePackageContract;
use App\Core\Module\ModuleTranslationContract;
use App\Core\Theme\ThemePackageContract;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

#[CoversClass(ModuleImportmapLoader::class)]
#[CoversClass(ModuleTranslationContract::class)]
#[CoversClass(ModulePackageContract::class)]
#[CoversClass(ModuleContributionReader::class)]
#[CoversClass(ThemePackageContract::class)]
final class ModulePackageContractTest extends TestCase
{
    private string $projectDir;

    protected function setUp(): void
    {
        $this->projectDir = sys_get_temp_dir().'/cpalius-mod-contract-'.bin2hex(random_bytes(4));
        mkdir($this->projectDir.'/cp-core/config', 0775, true);
        mkdir($this->projectDir.'/cp-content/modules/Demo/Resources/config', 0775, true);
        mkdir($this->projectDir.'/cp-content/modules/Demo/Resources/translations', 0775, true);
        mkdir($this->projectDir.'/cp-content/modules/Demo/Install', 0775, true);
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->projectDir);
    }

    public function testImportmapMergesActiveModuleEntries(): void
    {
        file_put_contents(
            $this->projectDir.'/cp-core/config/active_modules.php',
            "<?php\nreturn [Modules\\Demo\\DemoModule::class];\n",
        );
        file_put_contents(
            $this->projectDir.'/cp-content/modules/Demo/Resources/config/importmap.php',
            "<?php\nreturn ['demo-admin' => ['path' => 'Resources/assets/demo.js', 'entrypoint' => true]];\n",
        );

        $merged = ModuleImportmapLoader::merge(['app' => ['path' => './cp-core/assets/app.js']], $this->projectDir);

        self::assertSame('./demo.js', $merged['demo-admin']['path']);
        self::assertTrue($merged['demo-admin']['entrypoint']);
        self::assertArrayHasKey('app', $merged);
    }

    public function testEnglishCatalogueIsRequired(): void
    {
        $moduleDir = $this->projectDir.'/cp-content/modules/Demo';
        self::assertNotSame([], ModuleTranslationContract::problems($moduleDir));

        file_put_contents(ModuleTranslationContract::englishCataloguePath($moduleDir), "demo.ok: \"Demo\"\n");
        self::assertSame([], ModuleTranslationContract::problems($moduleDir));
    }

    public function testContributionsMergeNodeShowRoutes(): void
    {
        $a = ModuleContributionReader::normalize([
            'node_show_routes' => ['post' => 'blog_show'],
        ]);
        $b = ModuleContributionReader::normalize([
            'node_show_routes' => ['page' => 'page_show'],
            'queryable_fields' => ['page' => ['template' => 'string']],
        ]);
        $merged = ModuleContributionReader::merge($a, $b);

        self::assertSame('blog_show', $merged['node_show_routes']['post']);
        self::assertSame('page_show', $merged['node_show_routes']['page']);
        self::assertSame('string', $merged['queryable_fields']['page']['template']);
    }

    public function testContributionsMergeHomepageSchemaAndAccount(): void
    {
        $a = ModuleContributionReader::normalize([
            'homepage_modes' => ['forum' => ['route' => 'forum_index', 'label' => 'Forum', 'priority' => 20]],
            'schema_types' => ['post' => 'BlogPosting'],
            'account' => ['post_login_route' => 'forum_index', 'post_login_priority' => 10],
        ]);
        $b = ModuleContributionReader::normalize([
            'homepage_modes' => ['blog' => ['route' => 'blog_index', 'label' => 'Blog', 'priority' => 10]],
            'portal_blocks' => [
                'latest_blog_posts' => [
                    'label' => 'Latest',
                    'supports_limit' => true,
                    'hide_on_landing' => true,
                    'requires_route' => 'blog_index',
                ],
            ],
            'account' => ['post_login_route' => 'blog_index', 'post_login_priority' => 5],
        ]);
        $merged = ModuleContributionReader::merge($a, $b);

        self::assertSame('forum_index', $merged['homepage_modes']['forum']['route']);
        self::assertSame('blog_index', $merged['homepage_modes']['blog']['route']);
        self::assertSame('BlogPosting', $merged['schema_types']['post']);
        self::assertSame('forum_index', $merged['account']['post_login_route']);
        self::assertTrue($merged['portal_blocks']['latest_blog_posts']['hideOnLanding']);
        self::assertSame('blog_index', $merged['portal_blocks']['latest_blog_posts']['requiresRoute']);
    }

    public function testPackageContractRejectsAppNamespaceAndMissingBundleFile(): void
    {
        $moduleDir = $this->projectDir.'/cp-content/modules/Demo';
        file_put_contents(ModuleTranslationContract::englishCataloguePath($moduleDir), "demo.ok: \"Demo\"\n");
        file_put_contents($moduleDir.'/Install/ModuleInstaller.php', "<?php\n");
        file_put_contents($moduleDir.'/module.json', json_encode([
            'name' => 'Demo',
            'version' => '1.0.0',
            'bundle' => 'Modules\\Demo\\DemoModule',
        ], \JSON_THROW_ON_ERROR));
        mkdir($moduleDir.'/Leak', 0775, true);
        file_put_contents($moduleDir.'/Leak/Bad.php', "<?php\nnamespace App\\Leak;\n");

        $problems = ModulePackageContract::problems($moduleDir);
        self::assertNotSame([], $problems);
        self::assertTrue($this->containsFragment($problems, 'namespace App'));
        self::assertTrue($this->containsFragment($problems, 'DemoModule.php'));
    }

    public function testThemeContractRequiresLayoutAndKebabCase(): void
    {
        $themeDir = $this->projectDir.'/cp-content/themes/BadTheme';
        mkdir($themeDir, 0775, true);
        file_put_contents($themeDir.'/theme.json', json_encode(['name' => 'Bad'], \JSON_THROW_ON_ERROR));

        $problems = ThemePackageContract::problems($themeDir);
        self::assertTrue($this->containsFragment($problems, 'kebab-case'));
        self::assertTrue($this->containsFragment($problems, 'layout.html.twig'));
        self::assertTrue($this->containsFragment($problems, 'version'));
    }

    public function testZipEntryProblemsRejectCorePaths(): void
    {
        self::assertNotSame([], ModulePackageContract::zipEntryProblems('../cp-core/Kernel.php'));
        self::assertNotSame([], ModulePackageContract::zipEntryProblems('cp-core/src/Kernel.php'));
        self::assertSame([], ModulePackageContract::zipEntryProblems('Demo/module.json'));
    }

    /**
     * @param list<string> $problems
     */
    private function containsFragment(array $problems, string $fragment): bool
    {
        foreach ($problems as $problem) {
            if (str_contains($problem, $fragment)) {
                return true;
            }
        }

        return false;
    }
}
