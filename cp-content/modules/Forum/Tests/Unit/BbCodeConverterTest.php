<?php

declare(strict_types=1);

namespace Modules\Forum\Tests\Unit;

use Modules\Forum\Markup\BbCodeConverter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(BbCodeConverter::class)]
final class BbCodeConverterTest extends TestCase
{
    private function convert(string $bbcode): string
    {
        return (new BbCodeConverter())->convert($bbcode);
    }

    public function testConvertsTheCommonInlineTags(): void
    {
        $html = $this->convert('[b]bold[/b] and [i]italic[/i] and [u]under[/u]');

        self::assertStringContainsString('<strong>bold</strong>', $html);
        self::assertStringContainsString('<em>italic</em>', $html);
        self::assertStringContainsString('<u>under</u>', $html);
    }

    /**
     * The decisive property: forums are full of old posts containing raw HTML,
     * some of it left there by people testing exactly this. Escaping happens
     * before any tag is converted, so it comes out readable rather than live.
     */
    public function testRawHtmlInTheSourcePostBecomesVisibleTextNotMarkup(): void
    {
        $html = $this->convert('<script>alert(1)</script> hello');

        self::assertStringNotContainsString('<script>', $html);
        self::assertStringContainsString('&lt;script&gt;', $html);
    }

    #[DataProvider('dangerousUrls')]
    public function testALinkWithADangerousSchemeKeepsItsTextAndLosesItsHref(string $bbcode): void
    {
        $html = $this->convert($bbcode);

        self::assertStringNotContainsString('href=', $html, 'nothing dangerous should be clickable');
        self::assertStringContainsString('click', $html, 'the reader still sees what was written');
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function dangerousUrls(): iterable
    {
        yield 'javascript' => ['[url=javascript:alert(1)]click[/url]'];
        yield 'data' => ['[url=data:text/html;base64,PHNjcmlwdD4=]click[/url]'];
        yield 'vbscript' => ['[url=vbscript:msgbox]click[/url]'];
        yield 'entity encoded' => ['[url=java&#115;cript:alert(1)]click[/url]'];
    }

    public function testAnOrdinaryLinkSurvivesAndIsNotFollowed(): void
    {
        $html = $this->convert('[url=https://example.test/page]example[/url]');

        self::assertStringContainsString('href="https://example.test/page"', $html);
        self::assertStringContainsString('rel="nofollow noopener"', $html);
        self::assertStringContainsString('>example</a>', $html);
    }

    public function testAnImageWithADangerousSourceIsDroppedEntirely(): void
    {
        $html = $this->convert('[img]javascript:alert(1)[/img]');

        self::assertStringNotContainsString('<img', $html);
    }

    public function testAnOrdinaryImageSurvives(): void
    {
        $html = $this->convert('[img]https://example.test/a.png[/img]');

        self::assertStringContainsString('<img src="https://example.test/a.png" alt="">', $html);
    }

    public function testQuotesBecomeBlockquotesAndKeepTheAuthor(): void
    {
        $html = $this->convert('[quote=Ali]said this[/quote]');

        self::assertStringContainsString('<blockquote>', $html);
        self::assertStringContainsString('<cite>Ali</cite>', $html);
        self::assertStringContainsString('said this', $html);
    }

    public function testNestedQuotesAreAllConverted(): void
    {
        $html = $this->convert('[quote=A]outer [quote=B]inner[/quote][/quote]');

        self::assertStringNotContainsString('[quote', $html);
        self::assertStringNotContainsString('[/quote]', $html);
        self::assertSame(2, substr_count($html, '<blockquote>'));
    }

    public function testCodeBlocksKeepTheirContentsAsText(): void
    {
        $html = $this->convert('[code]<b>not bold</b>[/code]');

        self::assertStringContainsString('<pre><code>', $html);
        self::assertStringContainsString('&lt;b&gt;not bold&lt;/b&gt;', $html);
    }

    public function testListsBecomeListElements(): void
    {
        $html = $this->convert('[list][*]one[*]two[/list]');

        self::assertStringContainsString('<ul><li>one</li><li>two</li></ul>', $html);
    }

    public function testNumberedListsBecomeOrderedLists(): void
    {
        $html = $this->convert('[list=1][*]one[*]two[/list]');

        self::assertStringContainsString('<ol><li>one</li><li>two</li></ol>', $html);
    }

    public function testAColourThatCouldCloseTheAttributeIsDroppedButTheTextIsKept(): void
    {
        $html = $this->convert('[color=red" onload="alert(1)]text[/color]');

        self::assertStringNotContainsString('onload', $html);
        self::assertStringContainsString('text', $html);
    }

    public function testAnOrdinaryColourSurvives(): void
    {
        self::assertStringContainsString('style="color:#ff0000"', $this->convert('[color=#ff0000]red[/color]'));
        self::assertStringContainsString('style="color:red"', $this->convert('[color=red]red[/color]'));
    }

    /**
     * BBCode has no standard and every package extends it. Swallowing a tag
     * this does not know would delete content; leaving it visible is the
     * failure mode somebody can actually fix.
     */
    public function testAnUnknownTagIsLeftAloneRatherThanSwallowed(): void
    {
        $html = $this->convert('[xf:custom]something important[/xf:custom]');

        self::assertStringContainsString('something important', $html);
        self::assertStringContainsString('[xf:custom]', $html);
    }

    public function testBlankLinesBecomeParagraphs(): void
    {
        $html = $this->convert("first line\n\nsecond line");

        self::assertSame('<p>first line</p><p>second line</p>', $html);
    }

    public function testSingleNewlinesBecomeLineBreaks(): void
    {
        self::assertStringContainsString('<br>', $this->convert("one\ntwo"));
    }

    public function testABlockElementIsNotWrappedInAParagraph(): void
    {
        $html = $this->convert('[quote]quoted[/quote]');

        self::assertStringStartsWith('<blockquote>', $html);
    }

    public function testEmptyInputStaysEmpty(): void
    {
        self::assertSame('', $this->convert('   '));
    }
}
