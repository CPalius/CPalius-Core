<?php

declare(strict_types=1);

namespace App\Tests\Unit\Core\Migrate;

use App\Core\Migrate\MigrationRow;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(MigrationRow::class)]
final class MigrationRowTest extends TestCase
{
    public function testAnEmptySourceIdIsRefused(): void
    {
        // Without a stable key the map cannot make a re-run idempotent, so a
        // row without one is a defect in the source driver, not a data problem
        // to carry forward.
        $this->expectException(\InvalidArgumentException::class);

        new MigrationRow('   ', ['title' => 'x']);
    }

    public function testChecksumIgnoresKeyOrder(): void
    {
        $a = new MigrationRow('1', ['title' => 'x', 'body' => 'y']);
        $b = new MigrationRow('1', ['body' => 'y', 'title' => 'x']);

        self::assertSame($a->checksum(), $b->checksum());
    }

    public function testChecksumIgnoresNestedKeyOrderToo(): void
    {
        $a = new MigrationRow('1', ['meta' => ['b' => 2, 'a' => 1]]);
        $b = new MigrationRow('1', ['meta' => ['a' => 1, 'b' => 2]]);

        self::assertSame($a->checksum(), $b->checksum());
    }

    public function testChecksumChangesWhenAValueChanges(): void
    {
        $a = new MigrationRow('1', ['title' => 'before']);
        $b = new MigrationRow('1', ['title' => 'after']);

        self::assertNotSame($a->checksum(), $b->checksum());
    }

    public function testGetStringFallsBackForValuesThatCannotBeStrings(): void
    {
        $row = new MigrationRow('1', ['nested' => ['a'], 'missing' => null, 'n' => 5]);

        self::assertSame('fallback', $row->getString('nested', 'fallback'));
        self::assertSame('fallback', $row->getString('missing', 'fallback'));
        self::assertSame('fallback', $row->getString('absent', 'fallback'));
        self::assertSame('5', $row->getString('n'));
    }

    public function testHasDistinguishesANullValueFromAMissingKey(): void
    {
        $row = new MigrationRow('1', ['present' => null]);

        self::assertTrue($row->has('present'));
        self::assertFalse($row->has('absent'));
    }

    public function testWithDataKeepsTheSourceId(): void
    {
        $row = (new MigrationRow('42', ['a' => 1]))->withData(['b' => 2]);

        self::assertSame('42', $row->sourceId);
        self::assertSame(['b' => 2], $row->data);
    }
}
