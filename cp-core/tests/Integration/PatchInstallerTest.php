<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Core\Cache\CacheRebuildManager;
use App\Core\Version\CpVersion;
use App\Core\Version\PatchChecker;
use App\Core\Version\PatchInstaller;
use App\Repository\SettingRepository;
use App\Tests\Support\IntegrationTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * End-to-end cover for the file-level patcher, against a throwaway project tree
 * and a scripted HTTP client.
 *
 * This is the one piece of CPalius that takes a remote list of paths and writes
 * them over a running site, so the tests that matter are the refusals. Each
 * "refused" case below asserts the same two things: the exception, and that the
 * live files are byte-for-byte what they were. An installer that reports
 * failure after having written four of seven files is not a failed update, it
 * is a site running a combination nobody has ever tested.
 */
#[CoversClass(PatchInstaller::class)]
#[CoversClass(PatchChecker::class)]
final class PatchInstallerTest extends IntegrationTestCase
{
    private const SOURCE = 'https://raw.githubusercontent.com/CPalius/CPalius-Core/v9.9.9/';
    private const INDEX_URL = 'https://raw.githubusercontent.com/CPalius/version/main/patches/index.json';
    private const MANIFEST_URL = 'https://raw.githubusercontent.com/CPalius/version/main/patches/9.9.9.json';

    private const VERSION_FILE = 'cp-core/src/Core/Version/CpVersion.php';
    private const TOUCHED_FILE = 'cp-content/modules/Blog/Service/BlogCommentService.php';
    private const DOOMED_FILE = 'cp-content/modules/Blog/Service/Obsolete.php';

    private string $projectDir;
    private Filesystem $fs;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fs = new Filesystem();
        $this->projectDir = sys_get_temp_dir().'/cpalius-patch-'.bin2hex(random_bytes(4));

        $this->fs->dumpFile($this->projectDir.'/'.self::VERSION_FILE, $this->versionFileBody(CpVersion::VERSION));
        $this->fs->dumpFile($this->projectDir.'/'.self::TOUCHED_FILE, "<?php // old body\n");
        $this->fs->dumpFile($this->projectDir.'/'.self::DOOMED_FILE, "<?php // to be removed\n");

        // Written by the installer, and also the thing blockers() checks for.
        $this->fs->mkdir($this->projectDir.'/cp-core/var');
    }

    protected function tearDown(): void
    {
        $this->fs->remove($this->projectDir);

        parent::tearDown();
    }

    public function testAppliesAPatchAndAdvancesTheRunningVersion(): void
    {
        $newVersionBody = $this->versionFileBody('9.9.9');
        $newServiceBody = "<?php // patched body\n";

        $installer = $this->installer($this->manifest([
            ['path' => self::VERSION_FILE, 'sha256' => hash('sha256', $newVersionBody), 'size' => strlen($newVersionBody)],
            ['path' => self::TOUCHED_FILE, 'sha256' => hash('sha256', $newServiceBody), 'size' => strlen($newServiceBody)],
            ['path' => self::DOOMED_FILE, 'action' => 'delete'],
        ]), [
            self::SOURCE.self::VERSION_FILE => $newVersionBody,
            self::SOURCE.self::TOUCHED_FILE => $newServiceBody,
        ]);

        $log = $installer->apply();

        self::assertSame($newVersionBody, file_get_contents($this->projectDir.'/'.self::VERSION_FILE));
        self::assertSame($newServiceBody, file_get_contents($this->projectDir.'/'.self::TOUCHED_FILE));
        self::assertFileDoesNotExist($this->projectDir.'/'.self::DOOMED_FILE, 'a delete entry removes the file');

        // The backup is the only copy of what was there a moment ago, and it is
        // kept on purpose — an operator who finds a regression an hour later
        // wants it.
        self::assertFileExists($this->projectDir.'/cp-core/var/update/patch-backup-9.9.9/'.self::TOUCHED_FILE);
        self::assertSame(
            "<?php // old body\n",
            file_get_contents($this->projectDir.'/cp-core/var/update/patch-backup-9.9.9/'.self::TOUCHED_FILE),
        );

        // Staging is large and worthless once applied.
        self::assertDirectoryDoesNotExist($this->projectDir.'/cp-core/var/update/patch-staging-9.9.9');

        self::assertArrayHasKey('9.9.9', $installer->appliedLedger());
        self::assertNotEmpty($log);
    }

    /**
     * The security boundary of the whole feature. A body that does not match
     * its declared digest must never reach the project tree — not even the
     * files that did match, because a partly applied patch is the state this
     * design exists to make impossible.
     */
    public function testRefusesAPatchWhoseFileFailsItsDigestAndWritesNothing(): void
    {
        $newVersionBody = $this->versionFileBody('9.9.9');

        $installer = $this->installer($this->manifest([
            ['path' => self::VERSION_FILE, 'sha256' => hash('sha256', $newVersionBody), 'size' => strlen($newVersionBody)],
            ['path' => self::TOUCHED_FILE, 'sha256' => str_repeat('0', 64), 'size' => 20],
        ]), [
            self::SOURCE.self::VERSION_FILE => $newVersionBody,
            self::SOURCE.self::TOUCHED_FILE => "<?php // tampered\n",
        ]);

        try {
            $installer->apply();
            self::fail('a digest mismatch must abort the patch');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('does not match its declared SHA-256', $e->getMessage());
        }

        $this->assertTreeUntouched();
    }

    /**
     * Without this check a manifest could announce 9.9.9 while shipping code
     * that still declares the old version: the patch would apply, the running
     * version would not move, and the same patch would be offered again on
     * every check for the rest of the installation's life.
     */
    public function testRefusesAPatchWhoseVersionFileDisagreesWithTheManifest(): void
    {
        $liarBody = $this->versionFileBody('1.2.3');

        $installer = $this->installer($this->manifest([
            ['path' => self::VERSION_FILE, 'sha256' => hash('sha256', $liarBody), 'size' => strlen($liarBody)],
        ]), [
            self::SOURCE.self::VERSION_FILE => $liarBody,
        ]);

        try {
            $installer->apply();
            self::fail('a lying version file must abort the patch');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('declares 1.2.3 but the patch claims 9.9.9', $e->getMessage());
        }

        $this->assertTreeUntouched();
    }

    public function testRefusesAPatchThatDoesNotUpgradeFromTheRunningVersion(): void
    {
        $body = $this->versionFileBody('9.9.9');

        $installer = $this->installer(
            $this->manifest(
                [['path' => self::VERSION_FILE, 'sha256' => hash('sha256', $body), 'size' => strlen($body)]],
                base: '0.0.1',
            ),
            [self::SOURCE.self::VERSION_FILE => $body],
            indexBase: '0.0.1',
        );

        // next() never offers it, because its base is not what is running.
        self::assertNull($installer->plan());

        // And apply() refuses at the blocker stage, before any network call —
        // the installer decides which patch is next from the running version,
        // so there is no request field an attacker could steer to skip a step
        // in the chain.
        $this->expectExceptionMessage('aacp.patch.blocker.none_pending');
        $installer->apply();
    }

    /**
     * A manifest naming a path outside the shippable trees is refused during
     * parsing, so the installer never even fetches it.
     */
    public function testRefusesAManifestReachingOutsideTheProject(): void
    {
        $body = $this->versionFileBody('9.9.9');

        $installer = $this->installer($this->manifest([
            ['path' => self::VERSION_FILE, 'sha256' => hash('sha256', $body), 'size' => strlen($body)],
            ['path' => 'public/uploads/shell.php', 'sha256' => str_repeat('a', 64), 'size' => 10],
        ]), [self::SOURCE.self::VERSION_FILE => $body]);

        $this->expectExceptionMessageMatches('/may not write/');
        $installer->plan();
    }

    // ---------------------------------------------------------------- helpers

    /**
     * A CpVersion.php that is real enough for the regex the installer uses to
     * read the constant back out of the staged file.
     */
    private function versionFileBody(string $version): string
    {
        return "<?php\n\nfinal class CpVersion\n{\n    public const VERSION = '".$version."';\n}\n";
    }

    /**
     * @param list<array<string, mixed>> $files
     *
     * @return array<string, mixed>
     */
    private function manifest(array $files, ?string $base = null): array
    {
        return [
            'schema' => 1,
            'version' => '9.9.9',
            'base' => $base ?? CpVersion::VERSION,
            'released_at' => '2026-09-20',
            'critical' => false,
            'summary' => 'Test patch.',
            'source' => self::SOURCE,
            'files' => $files,
        ];
    }

    /**
     * @param array<string, mixed>  $manifest
     * @param array<string, string> $bodies   file URL => body
     */
    private function installer(array $manifest, array $bodies, ?string $indexBase = null): PatchInstaller
    {
        $index = [
            'schema' => 1,
            'patches' => [[
                'version' => '9.9.9',
                'base' => $indexBase ?? CpVersion::VERSION,
                'released_at' => '2026-09-20',
                'critical' => false,
                'summary' => 'Test patch.',
                'manifest' => self::MANIFEST_URL,
            ]],
        ];

        $responses = static function (string $method, string $url) use ($manifest, $bodies, $index): MockResponse {
            return match (true) {
                str_starts_with($url, self::INDEX_URL) => new MockResponse(json_encode($index, JSON_THROW_ON_ERROR)),
                str_starts_with($url, self::MANIFEST_URL) => new MockResponse(json_encode($manifest, JSON_THROW_ON_ERROR)),
                isset($bodies[$url]) => new MockResponse($bodies[$url]),
                default => new MockResponse('not found', ['http_code' => 404]),
            };
        };

        $container = $this->container();

        /** @var SettingRepository $settings */
        $settings = $container->get(SettingRepository::class);
        /** @var EntityManagerInterface $em */
        $em = $container->get(EntityManagerInterface::class);
        /** @var CacheRebuildManager $cache */
        $cache = $container->get(CacheRebuildManager::class);

        $checker = new PatchChecker($settings, $em, new MockHttpClient($responses));
        $checker->refresh();

        return new PatchInstaller(
            $this->projectDir,
            $checker,
            $cache,
            $settings,
            $em,
            new MockHttpClient($responses),
        );
    }

    /**
     * The assertion that makes every refusal test worth writing: a patch that
     * was rejected leaves the installation exactly as it found it.
     */
    private function assertTreeUntouched(): void
    {
        self::assertSame(
            $this->versionFileBody(CpVersion::VERSION),
            file_get_contents($this->projectDir.'/'.self::VERSION_FILE),
            'the version file must not have moved',
        );
        self::assertSame(
            "<?php // old body\n",
            file_get_contents($this->projectDir.'/'.self::TOUCHED_FILE),
            'no file may be written when the patch was refused',
        );
        self::assertFileExists($this->projectDir.'/'.self::DOOMED_FILE, 'no file may be deleted when the patch was refused');
    }
}
