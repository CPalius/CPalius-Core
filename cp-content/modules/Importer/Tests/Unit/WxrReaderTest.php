<?php

declare(strict_types=1);

namespace Modules\Importer\Tests\Unit;

use Modules\Importer\Source\Wordpress\WxrItem;
use Modules\Importer\Source\Wordpress\WxrReader;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(WxrReader::class)]
#[CoversClass(WxrItem::class)]
final class WxrReaderTest extends TestCase
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

    private function sample(): WxrReader
    {
        return new WxrReader(__DIR__.'/../Fixtures/sample-export.xml');
    }

    public function testReadsChannelAuthors(): void
    {
        $authors = iterator_to_array($this->sample()->authors(), false);

        self::assertCount(2, $authors);
        self::assertSame('admin', $authors[0]['author_login']);
        self::assertSame('admin@eski.example', $authors[0]['author_email']);
        self::assertSame('Site Yöneticisi', $authors[0]['author_display_name']);
        self::assertSame('Ali', $authors[0]['author_first_name']);
    }

    /**
     * The regression this guards: SimpleXMLElement::children() with no argument
     * only returns children in the default namespace, so a <wp:author> fragment
     * came back as "no children" and every channel-level row vanished — with no
     * error anywhere, because a source that yields nothing looks exactly like an
     * export that contains nothing.
     */
    public function testChannelElementsAreFoundDespiteLivingInTheWordpressNamespace(): void
    {
        self::assertNotSame([], iterator_to_array($this->sample()->authors(), false));
        self::assertNotSame([], iterator_to_array($this->sample()->categories(), false));
        self::assertNotSame([], iterator_to_array($this->sample()->tags(), false));
    }

    public function testReadsCategoriesWithTheirParentSlug(): void
    {
        $categories = iterator_to_array($this->sample()->categories(), false);

        self::assertCount(2, $categories);
        self::assertSame('haberler', $categories[0]['category_nicename']);
        self::assertSame('', $categories[0]['category_parent']);
        self::assertSame('duyurular', $categories[1]['category_nicename']);
        self::assertSame('haberler', $categories[1]['category_parent']);
    }

    public function testReadsTags(): void
    {
        $tags = iterator_to_array($this->sample()->tags(), false);

        self::assertCount(1, $tags);
        self::assertSame('php', $tags[0]['tag_slug']);
        self::assertSame('PHP', $tags[0]['tag_name']);
    }

    public function testReadsEveryItemWhateverItsPostType(): void
    {
        $items = iterator_to_array($this->sample()->items(), false);

        // Two posts, an auto-draft, a page and two attachments: filtering is
        // the migration's job, so the reader must not quietly drop any of them.
        self::assertCount(6, $items);
        self::assertSame(['41', '42', '43', '8', '99', '100'], array_map(static fn (WxrItem $i): string => $i->postId, $items));
    }

    public function testReadsAnItemInFull(): void
    {
        $items = iterator_to_array($this->sample()->items(), false);
        $first = $items[0];

        self::assertSame('İlk Yazı', $first->title);
        self::assertSame('ilk-yazi', $first->slug);
        self::assertSame('publish', $first->status);
        self::assertSame('post', $first->postType);
        self::assertSame('admin', $first->creator);
        self::assertSame('1', $first->isSticky);
        // CDATA is unwrapped but its contents are NOT entity-decoded, and that
        // is the correct behaviour rather than an omission: the payload is HTML
        // that will be rendered, so "&amp;" is already the right thing to store.
        // Decoding it here would turn every escaped ampersand in a WordPress
        // site into a raw one and quietly break the markup around it.
        self::assertStringStartsWith('<p>Merhaba dünya &amp; hoş geldiniz.</p>', $first->content);
        self::assertSame('Kısa özet', $first->excerpt);
    }

    public function testSeparatesCategoriesFromTagsOnAnItem(): void
    {
        $first = iterator_to_array($this->sample()->items(), false)[0];

        self::assertSame(['haberler' => 'Haberler'], $first->termsIn('category'));
        self::assertSame(['php' => 'PHP'], $first->termsIn('post_tag'));
    }

    public function testReadsPostmetaAndComments(): void
    {
        $first = iterator_to_array($this->sample()->items(), false)[0];

        self::assertSame('99', $first->meta['_thumbnail_id'] ?? null);
        self::assertCount(1, $first->comments);
        self::assertSame('Okur', $first->comments[0]['comment_author']);
    }

    public function testPublishedAtPrefersTheGmtDate(): void
    {
        $first = iterator_to_array($this->sample()->items(), false)[0];

        // post_date is in the old site's timezone, which the export does not
        // record; post_date_gmt is the only one that means anything here.
        self::assertSame('2024-03-01 07:00:00', $first->publishedAt()?->format('Y-m-d H:i:s'));
    }

    public function testPublishedAtIsNullForWordpressZeroDates(): void
    {
        $autoDraft = iterator_to_array($this->sample()->items(), false)[2];

        self::assertSame('43', $autoDraft->postId);
        self::assertNull($autoDraft->publishedAt());
    }

    public function testReportsTheDeclaredWxrVersion(): void
    {
        self::assertSame('1.2', $this->sample()->version());
    }

    public function testStreamsRatherThanLoadingTheWholeFile(): void
    {
        $first = null;

        foreach ($this->sample()->items() as $item) {
            $first = $item;
            break;
        }

        self::assertInstanceOf(WxrItem::class, $first);
        self::assertSame('41', $first->postId);
    }

    public function testAFileThatIsNotAnExportIsRefusedWithAnActionableMessage(): void
    {
        $reader = new WxrReader($this->file('<?xml version="1.0"?><rss version="2.0"><channel><title>x</title></channel></rss>'));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/wxr_version.*Tools -> Export/s');

        $reader->assertReadable();
    }

    public function testAMissingFileIsRefused(): void
    {
        $reader = new WxrReader(sys_get_temp_dir().'/cpalius-no-such-export-'.bin2hex(random_bytes(4)).'.xml');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/does not exist or cannot be read/');

        $reader->assertReadable();
    }

    /**
     * A prefix is a local alias, so an export written by another tool may bind
     * the WordPress namespace to something other than "wp:". Matching on the
     * prefix would read such a file as empty.
     */
    public function testMatchesTheNamespaceRatherThanTheWpPrefix(): void
    {
        $xml = <<<'XML'
            <?xml version="1.0" encoding="UTF-8"?>
            <rss version="2.0" xmlns:zz="http://wordpress.org/export/1.1/">
            <channel>
                <zz:wxr_version>1.1</zz:wxr_version>
                <zz:author>
                    <zz:author_id>9</zz:author_id>
                    <zz:author_login>other</zz:author_login>
                    <zz:author_email>other@example.com</zz:author_email>
                    <zz:author_display_name>Other</zz:author_display_name>
                    <zz:author_first_name></zz:author_first_name>
                    <zz:author_last_name></zz:author_last_name>
                </zz:author>
            </channel>
            </rss>
            XML;

        $authors = iterator_to_array((new WxrReader($this->file($xml)))->authors(), false);

        self::assertCount(1, $authors);
        self::assertSame('other', $authors[0]['author_login']);
    }

    private function file(string $contents): string
    {
        $path = sys_get_temp_dir().'/cpalius-wxr-'.bin2hex(random_bytes(6)).'.xml';
        file_put_contents($path, $contents);
        $this->files[] = $path;

        return $path;
    }
}
