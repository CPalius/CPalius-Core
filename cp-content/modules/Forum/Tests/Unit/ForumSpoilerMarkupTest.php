<?php

declare(strict_types=1);

namespace Modules\Forum\Tests\Unit;

use Modules\Forum\Service\ForumSpoilerMarkup;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ForumSpoilerMarkup::class)]
final class ForumSpoilerMarkupTest extends TestCase
{
    public function testLockedOutputDropsInnerHtml(): void
    {
        $html = '<p>visible</p><div class="forum-spoiler"><p>SECRET-TOKEN</p></div>';
        $out = (new ForumSpoilerMarkup())->rewrite($html, false, 'Spoiler', 'Like or reply.');

        self::assertStringNotContainsString('SECRET-TOKEN', $out);
        self::assertStringContainsString('is-locked', $out);
        self::assertStringContainsString('Like or reply.', $out);
        self::assertStringContainsString('visible', $out);
        self::assertStringContainsString('Spoiler', $out);
    }

    public function testOpenOutputKeepsInnerHtml(): void
    {
        $html = '<div class="forum-spoiler"><p>SECRET-TOKEN</p></div>';
        $out = (new ForumSpoilerMarkup())->rewrite($html, true, 'Spoiler', '');

        self::assertStringContainsString('SECRET-TOKEN', $out);
        self::assertStringContainsString('is-open', $out);
        self::assertStringContainsString('forum-spoiler__content', $out);
        self::assertStringNotContainsString('is-locked', $out);
    }

    public function testQuoteStripRemovesSpoilerText(): void
    {
        $html = '<p>hello</p><div class="forum-spoiler"><p>SECRET-TOKEN</p></div><p>world</p>';
        $plain = (new ForumSpoilerMarkup())->stripForQuote($html);

        self::assertStringNotContainsString('SECRET-TOKEN', $plain);
        self::assertStringContainsString('hello', $plain);
        self::assertStringContainsString('world', $plain);
    }

    public function testLeavesHtmlWithoutSpoilersAlone(): void
    {
        $html = '<p>just a post</p>';

        self::assertSame($html, (new ForumSpoilerMarkup())->rewrite($html, false, 'Spoiler', 'hint'));
    }

    public function testUnwrapsRenderedChromeBeforeLocking(): void
    {
        $html = '<div class="forum-spoiler is-open"><div class="forum-spoiler__bar">Spoiler</div><div class="forum-spoiler__content"><p>SECRET-TOKEN</p></div></div>';
        $out = (new ForumSpoilerMarkup())->rewrite($html, false, 'Spoiler', 'hint');

        self::assertStringNotContainsString('SECRET-TOKEN', $out);
        self::assertStringContainsString('is-locked', $out);
    }
}
