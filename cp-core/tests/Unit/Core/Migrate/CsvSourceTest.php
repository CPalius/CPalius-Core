<?php

declare(strict_types=1);

namespace App\Tests\Unit\Core\Migrate;

use App\Core\Migrate\MigrationRow;
use App\Core\Migrate\Source\CsvSource;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(CsvSource::class)]
final class CsvSourceTest extends TestCase
{
    /** @var list<string> */
    private array $files = [];

    protected function tearDown(): void
    {
        foreach ($this->files as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }

        $this->files = [];

        parent::tearDown();
    }

    public function testReadsRowsKeyedByTheHeader(): void
    {
        $source = new CsvSource($this->file("id,title,body\n1,First,Hello\n2,Second,World\n"), 'id');

        $rows = iterator_to_array($source->rows(), false);

        self::assertCount(2, $rows);
        self::assertSame('1', $rows[0]->sourceId);
        self::assertSame('First', $rows[0]->get('title'));
        self::assertSame('World', $rows[1]->get('body'));
    }

    public function testCountsDataRowsWithoutTheHeader(): void
    {
        $source = new CsvSource($this->file("id,title\n1,a\n2,b\n3,c\n"), 'id');

        self::assertSame(3, $source->count());
    }

    public function testStripsTheUtf8BomExcelPutsOnTheFirstHeaderCell(): void
    {
        // Without stripping, the first column is named "\u{FEFF}id" and every
        // lookup of "id" misses — which presents as "the key column is empty".
        $source = new CsvSource($this->file("\u{FEFF}id,title\n7,Seven\n"), 'id');

        $rows = iterator_to_array($source->rows(), false);

        self::assertSame('7', $rows[0]->sourceId);
    }

    public function testBlankLinesAreIgnoredRatherThanReportedAsBrokenRows(): void
    {
        $source = new CsvSource($this->file("id,title\n1,a\n\n2,b\n"), 'id');

        self::assertCount(2, iterator_to_array($source->rows(), false));
    }

    public function testAMissingKeyColumnIsRefusedWithTheColumnsItDidFind(): void
    {
        $source = new CsvSource($this->file("ref,title\n1,a\n"), 'id');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/has no column "id".*ref, title/s');

        iterator_to_array($source->rows(), false);
    }

    public function testAnEmptyKeyValueIsRefused(): void
    {
        $source = new CsvSource($this->file("id,title\n,a\n"), 'id');

        // A row with no key cannot be re-imported without duplicating it, so
        // importing it quietly would break the second run, not the first.
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/no value in the key column/');

        iterator_to_array($source->rows(), false);
    }

    public function testARowWithTooFewValuesIsRefusedInsteadOfGuessing(): void
    {
        $source = new CsvSource($this->file("id,title,body\n1,only-two\n"), 'id');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/has 2 values but the header declares 3/');

        iterator_to_array($source->rows(), false);
    }

    public function testAnEmptyFileIsRefused(): void
    {
        $source = new CsvSource($this->file(''), 'id');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/expected a header row/');

        iterator_to_array($source->rows(), false);
    }

    public function testAMissingFileIsRefused(): void
    {
        $source = new CsvSource(sys_get_temp_dir().'/cpalius-does-not-exist-'.bin2hex(random_bytes(4)).'.csv', 'id');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/does not exist or cannot be read/');

        iterator_to_array($source->rows(), false);
    }

    public function testHonoursACustomDelimiter(): void
    {
        $source = new CsvSource($this->file("id;title\n1;Semicolons\n"), 'id', ';');

        $rows = iterator_to_array($source->rows(), false);

        self::assertSame('Semicolons', $rows[0]->get('title'));
    }

    public function testStreamsRatherThanBuildingTheWholeSetFirst(): void
    {
        $source = new CsvSource($this->file("id,title\n1,a\n2,b\n3,c\n"), 'id');

        $first = null;
        foreach ($source->rows() as $row) {
            $first = $row;
            break;
        }

        // Breaking out after one row must be possible, which it is not if rows()
        // materialises everything before returning.
        self::assertInstanceOf(MigrationRow::class, $first);
        self::assertSame('1', $first->sourceId);
    }

    public function testRejectsAMultiCharacterDelimiter(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new CsvSource('whatever.csv', 'id', '||');
    }

    private function file(string $contents): string
    {
        $path = sys_get_temp_dir().'/cpalius-csv-'.bin2hex(random_bytes(6)).'.csv';
        file_put_contents($path, $contents);
        $this->files[] = $path;

        return $path;
    }
}
