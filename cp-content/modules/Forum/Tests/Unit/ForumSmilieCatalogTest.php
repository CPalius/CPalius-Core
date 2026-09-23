<?php

declare(strict_types=1);

namespace Modules\Forum\Tests\Unit;

use Modules\Forum\Service\ForumSmilieCatalog;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Display-time replacement is what makes an imported ":)" light up. The
 * catalog must therefore work from the built-in XenForo codes even when the
 * smilie table has not been created yet.
 */
#[CoversClass(ForumSmilieCatalog::class)]
final class ForumSmilieCatalogTest extends TestCase
{
    public function testDefaultEmojiForKnownXenforoCodes(): void
    {
        self::assertSame('😊', ForumSmilieCatalog::defaultEmojiFor([':)', ':-)']));
        self::assertSame('😎', ForumSmilieCatalog::defaultEmojiFor([':cool:']));
        self::assertSame('', ForumSmilieCatalog::defaultEmojiFor([':custom:']));
    }

    public function testReplaceTurnsTriggersIntoMarkupWithoutATable(): void
    {
        $html = $this->catalog()->replace('hi :) and :cool:');

        self::assertNotNull($html);
        self::assertStringContainsString('forum-smilie--emoji', $html);
        self::assertStringContainsString('😊', $html);
        self::assertStringContainsString('😎', $html);
        self::assertStringNotContainsString(':)', $html);
        self::assertStringNotContainsString(':cool:', $html);
    }

    public function testLongerCodesWin(): void
    {
        $html = $this->catalog()->replace(':cool:');

        self::assertNotNull($html);
        self::assertStringContainsString('😎', $html);
        self::assertStringNotContainsString('😊', $html);
    }

    public function testUnrelatedTextIsLeftAlone(): void
    {
        self::assertNull($this->catalog()->replace('plain text, no smile'));
    }

    public function testTheEditorSetIsTheDefaultXenforoPalette(): void
    {
        $codes = [];
        foreach ($this->catalog()->forEditor() as $row) {
            array_push($codes, ...$row['codes']);
        }

        self::assertContains(':)', $codes);
        self::assertContains(':cool:', $codes);
        self::assertContains('<3', $codes);
    }

    private function catalog(): ForumSmilieCatalog
    {
        return new ForumSmilieCatalog();
    }
}
