<?php

declare(strict_types=1);

namespace Modules\Importer\Tests\Integration;

use App\Core\Content\SlugGenerator;
use App\Core\Media\AssetManager;
use App\Core\Media\MimeTypeAllowlist;
use App\Core\Migrate\Map\DoctrineMigrationMap;
use App\Core\Migrate\MigrationLookup;
use App\Core\Migrate\MigrationRunner;
use App\Entity\Asset;
use App\Entity\Node;
use App\Repository\AssetRepository;
use App\Repository\NodeRepository;
use App\Tests\Support\IntegrationTestCase;
use League\Flysystem\Filesystem;
use League\Flysystem\Local\LocalFilesystemAdapter;
use Modules\Importer\Migration\Wordpress\WordpressAttachmentMigration;
use Modules\Importer\Migration\Wordpress\WordpressAuthorMigration;
use Modules\Importer\Migration\Wordpress\WordpressCategoryMigration;
use Modules\Importer\Migration\Wordpress\WordpressPostMigration;
use Modules\Importer\Migration\Wordpress\WordpressTagMigration;
use Modules\Importer\Source\Wordpress\UploadsResolver;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Images actually arrive, and the markup points at them.
 *
 * This is the part that decides whether a migrated site looks migrated or
 * looks broken: content whose <img> tags still name the old domain works right
 * up until that domain is switched off, and then every picture disappears at
 * once, weeks after anyone was watching.
 */
#[CoversClass(WordpressAttachmentMigration::class)]
#[CoversClass(\Modules\Importer\Source\Wordpress\WordpressMediaIndex::class)]
#[CoversClass(\App\Core\Migrate\Destination\AssetDestination::class)]
final class WordpressMediaImportTest extends IntegrationTestCase
{
    private const FIXTURE = __DIR__.'/../Fixtures/sample-export.xml';
    private const UPLOADS = __DIR__.'/../Fixtures/uploads';

    private ?string $storageDir = null;

    protected function tearDown(): void
    {
        if ($this->storageDir !== null && is_dir($this->storageDir)) {
            $this->deleteTree($this->storageDir);
        }

        $this->storageDir = null;

        parent::tearDown();
    }

    /**
     * Storage is a throwaway directory rather than the container's, so the test
     * neither depends on how media storage happens to be configured nor leaves
     * files behind anywhere that matters.
     */
    private function assetManager(): AssetManager
    {
        $this->storageDir ??= sys_get_temp_dir().'/cpalius-assets-'.bin2hex(random_bytes(6));

        /** @var AssetRepository $assets */
        $assets = $this->em()->getRepository(Asset::class);
        $storage = new Filesystem(new LocalFilesystemAdapter($this->storageDir));

        return new AssetManager($storage, $this->em(), $assets, new MimeTypeAllowlist());
    }

    private function deleteTree(string $dir): void
    {
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($items as $item) {
            \assert($item instanceof \SplFileInfo);
            $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }

        @rmdir($dir);
    }

    private function runner(): MigrationRunner
    {
        return new MigrationRunner(new DoctrineMigrationMap($this->em()));
    }

    private function lookup(): MigrationLookup
    {
        return new MigrationLookup(new DoctrineMigrationMap($this->em()));
    }

    private function attachments(): WordpressAttachmentMigration
    {
        return (new WordpressAttachmentMigration($this->assetManager(), $this->em()))
            ->withOptions(['file' => self::FIXTURE, 'uploads' => self::UPLOADS]);
    }

    private function posts(): WordpressPostMigration
    {
        /** @var NodeRepository $nodes */
        $nodes = $this->em()->getRepository(Node::class);

        return (new WordpressPostMigration($this->em(), new SlugGenerator($nodes), $this->lookup()))
            ->withOptions(['file' => self::FIXTURE, 'locale' => 'tr']);
    }

    private function importEverything(): void
    {
        $em = $this->em();
        $runner = $this->runner();
        $options = ['file' => self::FIXTURE, 'locale' => 'tr'];

        $runner->run((new WordpressAuthorMigration($em))->withOptions(['file' => self::FIXTURE]), false);
        $runner->run((new WordpressCategoryMigration($em, $this->lookup()))->withOptions($options), false);
        $runner->run((new WordpressTagMigration($em))->withOptions($options), false);
        $runner->run($this->attachments(), false);
        $runner->run($this->posts(), false);
    }

    public function testAnAttachmentBecomesAnAssetAndAnSvgIsRefused(): void
    {
        $report = $this->runner()->run($this->attachments(), false);

        self::assertSame(1, $report->created());
        // SVG is not on the asset allowlist because it carries script. The file
        // genuinely did not come across, so it is a reported failure rather
        // than a silent omission.
        self::assertSame(1, $report->failed());
        self::assertSame('100', $report->failures()[0]['sourceId']);

        /** @var list<Asset> $assets */
        $assets = $this->em()->getRepository(Asset::class)->findBy([]);
        self::assertCount(1, $assets);
        self::assertSame('image/png', $assets[0]->getMimeType());
    }

    public function testTheAssetKeepsTheAltTextAndWhereItCameFrom(): void
    {
        $this->runner()->run($this->attachments(), false);

        /** @var Asset $asset */
        $asset = $this->em()->getRepository(Asset::class)->findBy([])[0];

        self::assertSame('Örnek görsel', $asset->getMetadata()['alt'] ?? null);
        self::assertStringContainsString('foto.png', (string) ($asset->getMetadata()['sourceUrl'] ?? ''));
    }

    /**
     * WordPress writes the derivative into the markup, not the original: the
     * body says foto-300x200.png while the export's attachment says foto.png.
     * Both have to land on the same asset or every inline image stays broken.
     */
    public function testASizedDerivativeInTheBodyIsRewrittenToTheImportedAsset(): void
    {
        $this->importEverything();
        $this->em()->clear();

        /** @var Asset $asset */
        $asset = $this->em()->getRepository(Asset::class)->findBy([])[0];
        $expected = '/uploads/'.trim($asset->getPath(), '/').'/'.$asset->getFilename();

        /** @var list<Node> $nodes */
        $nodes = $this->em()->getRepository(Node::class)->findBy([], ['id' => 'ASC']);
        $body = (string) ($nodes[0]->getData()['body'] ?? '');

        self::assertStringNotContainsString('eski.example', $body, 'The old domain must not survive in the markup.');
        self::assertStringContainsString('src="'.$expected.'"', $body);
        // The full-size link in the same body resolves to the same asset.
        self::assertStringContainsString('href="'.$expected.'"', $body);
    }

    public function testTheFeaturedImageIsResolvedFromThumbnailMeta(): void
    {
        $this->importEverything();
        $this->em()->clear();

        /** @var Asset $asset */
        $asset = $this->em()->getRepository(Asset::class)->findBy([])[0];
        /** @var list<Node> $nodes */
        $nodes = $this->em()->getRepository(Node::class)->findBy([], ['id' => 'ASC']);

        // WordPress names the featured image by attachment post id, not URL.
        self::assertSame((string) $asset->getId(), (string) ($nodes[0]->getData()['featuredAssetId'] ?? ''));
    }

    public function testAUrlWithNoImportedAssetIsLeftAloneRatherThanBlanked(): void
    {
        // Only attachments run: the post's image has no asset behind it yet.
        $this->runner()->run($this->posts(), false);
        $this->em()->clear();

        /** @var list<Node> $nodes */
        $nodes = $this->em()->getRepository(Node::class)->findBy([], ['id' => 'ASC']);
        $body = (string) ($nodes[0]->getData()['body'] ?? '');

        // Still pointing at the old site, which at least still works while it
        // is up — a guessed or emptied src would be broken immediately.
        self::assertStringContainsString('eski.example', $body);
    }

    public function testReimportingAttachmentsCreatesNothingNew(): void
    {
        $runner = $this->runner();
        $runner->run($this->attachments(), false);
        $report = $runner->run($this->attachments(), false);

        self::assertSame(0, $report->created());
        self::assertCount(1, $this->em()->getRepository(Asset::class)->findBy([]));
    }

    public function testTheUploadsDirectoryIsRequiredAndSaidSo(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/Missing required option.*uploads/s');

        (new WordpressAttachmentMigration($this->assetManager(), $this->em()))
            ->withOptions(['file' => self::FIXTURE]);
    }

    public function testAMissingFileIsReportedWithTheUrlAndTheDirectorySearched(): void
    {
        $empty = sys_get_temp_dir().'/cpalius-empty-uploads-'.bin2hex(random_bytes(6));
        mkdir($empty, 0o777, true);

        try {
            $migration = (new WordpressAttachmentMigration($this->assetManager(), $this->em()))
                ->withOptions(['file' => self::FIXTURE, 'uploads' => $empty]);

            $report = $this->runner()->run($migration, false);

            self::assertSame(2, $report->failed());
            self::assertStringContainsString('foto.png', $report->failures()[0]['message']);
            self::assertStringContainsString((new UploadsResolver($empty))->root(), $report->failures()[0]['message']);
        } finally {
            @rmdir($empty);
        }
    }
}
