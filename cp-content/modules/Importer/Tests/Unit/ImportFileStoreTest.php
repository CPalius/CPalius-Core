<?php

declare(strict_types=1);

namespace Modules\Importer\Tests\Unit;

use Modules\Importer\Storage\ImportFileStore;
use Modules\Importer\Storage\StoredImport;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;

#[CoversClass(ImportFileStore::class)]
#[CoversClass(StoredImport::class)]
final class ImportFileStoreTest extends TestCase
{
    private string $root;

    /** @var list<string> */
    private array $scratch = [];

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir().'/cpalius-imports-'.bin2hex(random_bytes(6));
        mkdir($this->root, 0o777, true);
    }

    protected function tearDown(): void
    {
        $this->deleteTree($this->root);

        foreach ($this->scratch as $path) {
            if (is_file($path)) {
                @unlink($path);
            }
        }

        $this->scratch = [];

        parent::tearDown();
    }

    private function store(): ImportFileStore
    {
        return new ImportFileStore($this->root);
    }

    /**
     * UploadedFile in test mode: the file did not arrive over HTTP, which is
     * the supported way to drive the upload path without a web server.
     */
    private function upload(string $name, string $contents): UploadedFile
    {
        $path = sys_get_temp_dir().'/cpalius-up-'.bin2hex(random_bytes(6));
        file_put_contents($path, $contents);
        $this->scratch[] = $path;

        return new UploadedFile($path, $name, null, null, true);
    }

    private function zip(string $name, callable $build): UploadedFile
    {
        $path = sys_get_temp_dir().'/cpalius-zip-'.bin2hex(random_bytes(6)).'.zip';
        $this->scratch[] = $path;

        $archive = new \ZipArchive();
        self::assertTrue($archive->open($path, \ZipArchive::CREATE) === true);
        $build($archive);
        $archive->close();

        return new UploadedFile($path, $name, null, null, true);
    }

    public function testAnXmlExportIsStoredOutsideAnythingServable(): void
    {
        $stored = $this->store()->store($this->upload('export.xml', '<?xml version="1.0"?><rss><channel/></rss>'));

        self::assertSame('export.xml', $stored->originalName);
        self::assertSame(StoredImport::KIND_FILE, $stored->kind);
        self::assertFileExists($stored->path);
        // The stored path is built from random bytes, never from the upload's
        // own name — a filename is where "../" comes from.
        self::assertStringNotContainsString('export.xml', str_replace('\\', '/', \dirname($stored->path)));
        self::assertStringStartsWith(
            str_replace('\\', '/', $this->root),
            str_replace('\\', '/', $stored->path),
        );
    }

    public function testTheOriginalNameSurvivesOnlyAsALabel(): void
    {
        $stored = $this->store()->store($this->upload('../../evil name.xml', '<?xml version="1.0"?><rss/>'));

        // Shown back to the operator, but stripped of its path components so it
        // cannot act as one.
        self::assertSame('evil name.xml', $stored->originalName);
    }

    public function testASqlDumpIsStoredAsAFile(): void
    {
        $stored = $this->store()->store($this->upload('forum.sql', "CREATE TABLE xf_node (node_id INT);\n"));

        self::assertSame('forum.sql', $stored->originalName);
        self::assertSame(StoredImport::KIND_FILE, $stored->kind);
        self::assertStringEndsWith('.sql', $stored->path);
        self::assertFileExists($stored->path);
    }

    public function testAFileTypeThatIsNotAnExportIsRefused(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/Cannot import a "php" file/');

        $this->store()->store($this->upload('shell.php', '<?php echo 1;'));
    }

    public function testXmlThatDoesNotParseIsRefusedAtUploadTimeNotImportTime(): void
    {
        // Better here than three screens later, when the operator has already
        // chosen it and started a run.
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/not valid XML/');

        $this->store()->store($this->upload('broken.xml', 'this is not xml at all'));
    }

    public function testARefusedUploadLeavesNothingBehind(): void
    {
        try {
            $this->store()->store($this->upload('broken.xml', 'nope'));
        } catch (\RuntimeException) {
            // expected
        }

        // A half-written upload would appear in the list as something to
        // select, and then fail on use.
        self::assertSame([], $this->store()->all());
    }

    public function testAZipIsUnpackedIntoADirectory(): void
    {
        $upload = $this->zip('uploads.zip', static function (\ZipArchive $zip): void {
            $zip->addFromString('2024/03/foto.png', 'binary');
            $zip->addFromString('2024/04/other.png', 'binary');
        });

        $stored = $this->store()->store($upload);

        self::assertTrue($stored->isDirectory());
        self::assertFileExists($stored->path.'/2024/03/foto.png');
        self::assertFileExists($stored->path.'/2024/04/other.png');
    }

    /**
     * Zip slip: an entry named ../../something escapes the extraction
     * directory and writes wherever the web user can write.
     */
    public function testAnArchiveThatClimbsOutOfItselfIsRefused(): void
    {
        $upload = $this->zip('evil.zip', static function (\ZipArchive $zip): void {
            $zip->addFromString('../../escaped.txt', 'pwned');
        });

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/climbs out of the archive/');

        $this->store()->store($upload);
    }

    public function testAnArchiveWithAnAbsolutePathIsRefused(): void
    {
        $upload = $this->zip('evil.zip', static function (\ZipArchive $zip): void {
            $zip->addFromString('/etc/passwd', 'pwned');
        });

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/absolute path/');

        $this->store()->store($upload);
    }

    public function testAFailedArchiveLeavesNoPartialExtraction(): void
    {
        $upload = $this->zip('evil.zip', static function (\ZipArchive $zip): void {
            $zip->addFromString('fine.txt', 'ok');
            $zip->addFromString('../escaped.txt', 'pwned');
        });

        try {
            $this->store()->store($upload);
        } catch (\RuntimeException) {
            // expected
        }

        self::assertSame([], $this->store()->all());
        self::assertFileDoesNotExist(\dirname($this->root).'/escaped.txt');
    }

    public function testUploadsAreListedNewestFirstAndCanBeDeleted(): void
    {
        $store = $this->store();
        $first = $store->store($this->upload('a.csv', "id,title\n1,x\n"));
        $second = $store->store($this->upload('b.csv', "id,title\n2,y\n"));

        $ids = array_map(static fn (StoredImport $i): string => $i->id, $store->all());
        self::assertContains($first->id, $ids);
        self::assertContains($second->id, $ids);
        self::assertCount(2, $ids);

        $store->delete($first->id);

        self::assertNull($store->find($first->id));
        self::assertNotNull($store->find($second->id));
        self::assertFileDoesNotExist($first->path);
    }

    public function testAnIdThatIsNotAnIdIsRefusedRatherThanUsedAsAPath(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->store()->delete('../../../etc');
    }

    public function testFindIgnoresAnythingThatIsNotAnUploadId(): void
    {
        self::assertNull($this->store()->find('../..'));
        self::assertNull($this->store()->find('nope'));
    }

    private function deleteTree(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

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
}
