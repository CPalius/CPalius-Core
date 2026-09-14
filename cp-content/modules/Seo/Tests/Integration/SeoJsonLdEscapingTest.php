<?php

declare(strict_types=1);

namespace Modules\Seo\Tests\Integration;

use App\Core\Localization\Twig\LocaleRuntime;
use App\Core\Settings\SettingsRegistry;
use App\Tests\Support\IntegrationTestCase;
use Modules\Seo\Contract\SeoPageProviderInterface;
use Modules\Seo\Document\SeoDocument;
use Modules\Seo\Engine\SchemaGraphBuilder;
use Modules\Seo\Engine\SeoEngine;
use Modules\Seo\Engine\SeoUrlBuilder;
use Modules\Seo\Engine\TitleFormatter;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Twig\Environment;

/**
 * JSON-LD is printed |raw in <script>; this asserts a hostile headline cannot close the tag.
 */
#[CoversClass(SeoEngine::class)]
final class SeoJsonLdEscapingTest extends IntegrationTestCase
{
    private const PAYLOAD = '</script><img src=x onerror=alert(document.domain)>';

    /** Topic title and display name both reach the graph as member-controlled input. */
    public function testHostileHeadlineAndAuthorCannotEscapeTheScriptElement(): void
    {
        $head = $this->renderHeadFor(new SeoDocument(
            headline: self::PAYLOAD,
            description: self::PAYLOAD,
            canonicalPath: '/tr/forums/thread/1-poc',
            contentKind: 'forum',
            schemaType: 'DiscussionForumPosting',
            authorName: self::PAYLOAD,
            locale: 'tr',
        ));

        $jsonLd = $this->jsonLdBlock($head);

        self::assertStringNotContainsString('</script>', $jsonLd, 'The JSON-LD payload must not be able to close its own <script> element.');
        self::assertStringNotContainsString('<img', $head, 'No tag from document data may reach the page as markup.');
        self::assertStringNotContainsString('<', $jsonLd, 'No "<" may survive encoding inside the JSON-LD block.');
    }

    /** Escaped graph must stay valid JSON and round-trip the original string. */
    public function testEscapedGraphIsStillValidJsonAndPreservesTheValue(): void
    {
        $head = $this->renderHeadFor(new SeoDocument(
            headline: self::PAYLOAD,
            canonicalPath: '/tr/forums/thread/1-poc',
            locale: 'tr',
        ));

        $decoded = json_decode($this->jsonLdBlock($head), true, 32, \JSON_THROW_ON_ERROR);

        self::assertIsArray($decoded);
        self::assertSame('https://schema.org', $decoded['@context'] ?? null);

        // Headline is passed through; "name" is the formatted title and is not compared byte-for-byte.
        $headline = null;
        foreach ($decoded['@graph'] ?? [] as $node) {
            if (\is_array($node) && isset($node['headline'])) {
                $headline = $node['headline'];
                break;
            }
        }

        self::assertNotNull($headline, 'The headline should still be present in the graph, only escaped.');
        self::assertSame(self::PAYLOAD, $headline, 'Escaping must be lossless — consumers decode it back to the original string.');
    }

    /** Decoded URLs must still be usable after HEX escaping. */
    public function testUrlsSurviveEncoding(): void
    {
        $head = $this->renderHeadFor(new SeoDocument(
            headline: 'Plain title',
            canonicalPath: '/tr/forums/thread/1-poc',
            locale: 'tr',
        ));

        $decoded = json_decode($this->jsonLdBlock($head), true, 32, \JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        $urls = [];
        array_walk_recursive($decoded, static function (mixed $value) use (&$urls): void {
            if (\is_string($value) && str_starts_with($value, 'http')) {
                $urls[] = $value;
            }
        });

        self::assertNotSame([], $urls);
        foreach ($urls as $url) {
            self::assertStringNotContainsString('\\/', $url, 'A decoded URL must not carry literal backslash-escaped slashes.');
        }
    }

    private function renderHeadFor(SeoDocument $document): string
    {
        $container = $this->container();

        $request = Request::create('https://example.test/tr/forums/thread/1-poc');
        $request->setLocale('tr');
        $stack = new RequestStack();
        $stack->push($request);

        /** @var SettingsRegistry $settings */
        $settings = $container->get(SettingsRegistry::class);
        /** @var TitleFormatter $titles */
        $titles = $container->get(TitleFormatter::class);
        /** @var SeoUrlBuilder $urls */
        $urls = $container->get(SeoUrlBuilder::class);
        /** @var SchemaGraphBuilder $schema */
        $schema = $container->get(SchemaGraphBuilder::class);
        /** @var LocaleRuntime $locales */
        $locales = $container->get(LocaleRuntime::class);
        /** @var Environment $twig */
        $twig = $container->get(Environment::class);

        $engine = new SeoEngine(
            [$this->providerReturning($document)],
            $stack,
            $settings,
            $titles,
            $urls,
            $schema,
            $locales,
            $twig,
        );

        return $engine->render();
    }

    private function providerReturning(SeoDocument $document): SeoPageProviderInterface
    {
        return new class($document) implements SeoPageProviderInterface {
            public function __construct(private readonly SeoDocument $document)
            {
            }

            public function supports(Request $request): bool
            {
                return true;
            }

            public function document(Request $request): SeoDocument
            {
                return $this->document;
            }

            public function priority(): int
            {
                return 1000;
            }
        };
    }

    private function jsonLdBlock(string $head): string
    {
        self::assertSame(
            1,
            preg_match('#<script type="application/ld\+json">(.*?)</script>#s', $head, $matches),
            'The head must contain exactly one parseable JSON-LD block.',
        );

        return $matches[1];
    }
}
