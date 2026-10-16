<?php

declare(strict_types=1);

namespace App\Tests\Unit\Core\Homepage;

use App\Core\Homepage\HomepageHtmlGuard;
use App\Core\Homepage\HomepageStore;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(HomepageHtmlGuard::class)]
#[CoversClass(HomepageStore::class)]
final class HomepageSafetyTest extends TestCase
{
    /** @return iterable<string, array{string}> */
    public static function refusedScripts(): iterable
    {
        yield 'eval' => ['eval("1")'];
        yield 'Function' => ['new Function("return 1")()'];
        yield 'cookie' => ['var c = document.cookie;'];
        yield 'localStorage' => ['localStorage.setItem("a","b")'];
        yield 'fetch' => ['fetch("/x")'];
        yield 'xhr' => ['new XMLHttpRequest()'];
        yield 'innerHTML' => ['el.innerHTML = "<b>x</b>";'];
        yield 'document.write' => ['document.write("x")'];
        yield 'computed global' => ['window["ev"+"al"]("1")'];
        yield 'redirect href' => ['window.location.href = "https://evil.example"'];
        yield 'redirect bare' => ['location = "https://evil.example"'];
        yield 'redirect replace' => ['location.replace("https://evil.example")'];
        yield 'parent' => ['parent.document.title = "x"'];
        yield 'string timer' => ['setTimeout("alert(1)", 10)'];
        yield 'script element' => ['document.createElement("script")'];
        yield 'dynamic import' => ['import("https://evil.example/x.js")'];
        yield 'closing tag' => ['var s = "</script><script>x";'];
    }

    #[DataProvider('refusedScripts')]
    public function testScriptThatReachesForForbiddenThingsIsRefused(string $js): void
    {
        $report = (new HomepageHtmlGuard())->check(['html' => '', 'css' => '', 'js' => $js]);

        self::assertNotSame([], $report['errors'], $js);
    }

    public function testOrdinaryScriptPasses(): void
    {
        $js = "document.getElementById('go').addEventListener('click', function () {\n  document.getElementById('out').textContent = 'It works.';\n  var y = window.scrollY; var loc = 'location';\n});";

        self::assertSame([], (new HomepageHtmlGuard())->check(['html' => '', 'css' => '', 'js' => $js])['errors']);
    }

    public function testMarkupLosesScriptsHandlersAndJavascriptUrls(): void
    {
        $report = (new HomepageHtmlGuard())->check([
            'html' => '<div onclick="x()"><script>alert(1)</script><a href="javascript:alert(1)">a</a><iframe src="https://e.example"></iframe><form action="/x"><input name="a"></form><p class="k">ok</p></div>',
            'css' => '',
            'js' => '',
        ]);

        self::assertStringNotContainsString('<script', $report['html']);
        self::assertStringNotContainsString('onclick', $report['html']);
        self::assertStringNotContainsString('javascript:', $report['html']);
        self::assertStringNotContainsString('<iframe', $report['html']);
        self::assertStringNotContainsString('<form', $report['html']);
        self::assertStringContainsString('<p class="k">ok</p>', $report['html']);
        self::assertNotSame([], $report['notes']);
    }

    /** @return iterable<string, array{string}> */
    public static function refusedCss(): iterable
    {
        yield 'import' => ['@import url("https://e.example/a.css");'];
        yield 'expression' => ['a { width: expression(alert(1)); }'];
        yield 'js url' => ['a { background: url(javascript:alert(1)); }'];
        yield 'protocol-relative url' => ['a { background: url(//e.example/x.png); }'];
        yield 'http url' => ['a { background: url(http://e.example/x.png); }'];
        yield 'style tag' => ['</style><script>x</script>'];
    }

    #[DataProvider('refusedCss')]
    public function testCssThatLoadsCodeOrOffSiteResourcesIsRefused(string $css): void
    {
        self::assertNotSame([], (new HomepageHtmlGuard())->check(['html' => '', 'css' => $css, 'js' => ''])['errors'], $css);
    }

    public function testCssWithSiteAndHttpsImagesPasses(): void
    {
        $css = 'a { background: url("/uploads/a.png"); } b { background: url(https://cdn.example/x.webp); } c { background: url(data:image/png;base64,AAAA); }';

        self::assertSame([], (new HomepageHtmlGuard())->check(['html' => '', 'css' => $css, 'js' => ''])['errors']);
    }

    public function testNormalizeKeepsOnlyKnownLanguagesAndCleansValues(): void
    {
        $doc = HomepageStore::normalize([
            'engine' => 'evil',
            'o' => [
                'hero.cta_download@url' => ['tr' => 'javascript:alert(1)', 'en' => 'https://example.com/get', 'xx' => 'https://other.example'],
                'hero.code_note_html' => ['tr' => '<strong>a</strong><script>x</script><img src=x onerror=y>'],
                'hero.desc' => ['tr' => "  plain \x00text  "],
                'bad key!' => ['tr' => 'x'],
            ],
            'h' => ['hero.code' => true, 'hero.meta' => false],
            'seo' => ['title' => ['tr' => str_repeat('a', 500)], 'image' => 'javascript:x', 'noindex' => 1],
        ], ['tr', 'en']);

        self::assertSame('blocks', $doc['engine']);
        self::assertSame('', $doc['o']['hero.cta_download@url']['tr'], 'javascript: URLs are emptied, not stored');
        self::assertSame('https://example.com/get', $doc['o']['hero.cta_download@url']['en']);
        self::assertArrayNotHasKey('xx', $doc['o']['hero.cta_download@url']);
        self::assertStringNotContainsString('<script', $doc['o']['hero.code_note_html']['tr']);
        self::assertStringNotContainsString('onerror', $doc['o']['hero.code_note_html']['tr']);
        self::assertStringContainsString('<strong>a</strong>', $doc['o']['hero.code_note_html']['tr']);
        self::assertSame('plain text', $doc['o']['hero.desc']['tr']);
        self::assertArrayNotHasKey('bad key!', $doc['o']);
        self::assertSame(['hero.code' => true], $doc['h']);
        self::assertSame(180, mb_strlen($doc['seo']['title']['tr']));
        self::assertSame('', $doc['seo']['image']);
        self::assertTrue($doc['seo']['noindex']);
    }

    public function testAnEmptyOverrideSurvivesNormalisationBecauseItMeansRemoveTheElement(): void
    {
        $doc = HomepageStore::normalize(['o' => ['hero.cta_download@url' => ['tr' => '']]], ['tr']);

        self::assertSame('', $doc['o']['hero.cta_download@url']['tr']);
    }
}
