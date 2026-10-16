<?php

declare(strict_types=1);

namespace App\Tests\Unit\Core\Export;

use App\Core\Export\CsvWriter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(CsvWriter::class)]
final class CsvWriterTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function cells(): iterable
    {
        yield 'plain text' => ['Ayşe Yılmaz', 'Ayşe Yılmaz'];
        yield 'formula' => ['=1+1', "'=1+1"];
        yield 'hyperlink formula' => ['=HYPERLINK("http://x","y")', "'=HYPERLINK(\"http://x\",\"y\")"];
        yield 'plus formula' => ['+cmd|x', "'+cmd|x"];
        yield 'at sign' => ['@SUM(A1)', "'@SUM(A1)"];
        yield 'minus text' => ['-cmd', "'-cmd"];
        yield 'leading space formula' => [' =1+1', "' =1+1"];
        yield 'tab formula' => ["\t=1", "'\t=1"];
        yield 'negative number untouched' => ['-5', '-5'];
        yield 'decimal untouched' => ['12.50', '12.50'];
        yield 'empty' => ['', ''];
    }

    #[DataProvider('cells')]
    public function testSafeCell(string $input, string $expected): void
    {
        self::assertSame($expected, CsvWriter::safeCell($input));
    }

    public function testWritesQuotedRowsAndNulls(): void
    {
        $handle = fopen('php://memory', 'w+');
        CsvWriter::writeRow($handle, ['a,b', 'say "hi"', null, 3, '=x']);
        rewind($handle);

        self::assertSame("\"a,b\",\"say \"\"hi\"\"\",,3,'=x\r\n", stream_get_contents($handle));
    }
}
