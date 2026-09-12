<?php

declare(strict_types=1);

namespace Modules\Importer\Tests\Unit;

use App\Core\Migrate\MigrationRow;
use Modules\Importer\Source\Wordpress\WxrAuthorSource;
use Modules\Importer\Source\Wordpress\WxrPostSource;
use Modules\Importer\Source\Wordpress\WxrReader;
use Modules\Importer\Source\Wordpress\WxrTermSource;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(WxrAuthorSource::class)]
#[CoversClass(WxrTermSource::class)]
#[CoversClass(WxrPostSource::class)]
final class WxrSourceTest extends TestCase
{
    private function reader(): WxrReader
    {
        return new WxrReader(__DIR__.'/../Fixtures/sample-export.xml');
    }

    /**
     * @param iterable<MigrationRow> $source
     *
     * @return list<MigrationRow>
     */
    private function rows(iterable $source): array
    {
        return iterator_to_array($source, false);
    }

    public function testAuthorsAreKeyedByLoginBecauseThatIsWhatPostsReference(): void
    {
        $rows = $this->rows((new WxrAuthorSource($this->reader()))->rows());

        self::assertSame(['admin', 'nomail'], array_map(static fn (MigrationRow $r): string => $r->sourceId, $rows));
        self::assertSame('admin@eski.example', $rows[0]->get('email'));
        self::assertSame('Site Yöneticisi', $rows[0]->get('displayName'));
    }

    public function testAuthorSourceCountsWithoutReadingTheBodyOfTheExport(): void
    {
        self::assertSame(2, (new WxrAuthorSource($this->reader()))->count());
    }

    public function testCategoriesComeThroughWithTheirParentSlug(): void
    {
        $rows = $this->rows((new WxrTermSource($this->reader(), WxrTermSource::TAXONOMY_CATEGORY))->rows());

        self::assertSame(['haberler', 'duyurular'], array_map(static fn (MigrationRow $r): string => $r->sourceId, $rows));
        self::assertSame('haberler', $rows[1]->get('parentSlug'));
        self::assertSame('Genel haberler', $rows[0]->get('description'));
    }

    public function testTagsAreFlat(): void
    {
        $rows = $this->rows((new WxrTermSource($this->reader(), WxrTermSource::TAXONOMY_TAG))->rows());

        self::assertCount(1, $rows);
        self::assertSame('php', $rows[0]->sourceId);
        self::assertSame('', $rows[0]->get('parentSlug'));
    }

    public function testTermSourcesDoNotLeakIntoEachOther(): void
    {
        $categories = $this->rows((new WxrTermSource($this->reader(), WxrTermSource::TAXONOMY_CATEGORY))->rows());
        $tags = $this->rows((new WxrTermSource($this->reader(), WxrTermSource::TAXONOMY_TAG))->rows());

        $categorySlugs = array_map(static fn (MigrationRow $r): string => $r->sourceId, $categories);
        $tagSlugs = array_map(static fn (MigrationRow $r): string => $r->sourceId, $tags);

        self::assertSame([], array_intersect($categorySlugs, $tagSlugs));
    }

    public function testPostSourceReadsOnlyItsOwnPostType(): void
    {
        $posts = $this->rows((new WxrPostSource($this->reader(), 'post'))->rows());
        $pages = $this->rows((new WxrPostSource($this->reader(), 'page'))->rows());

        self::assertSame(['41', '42'], array_map(static fn (MigrationRow $r): string => $r->sourceId, $posts));
        self::assertSame(['8'], array_map(static fn (MigrationRow $r): string => $r->sourceId, $pages));
    }

    /**
     * WordPress keeps every autosave as a post row. On a long-lived site there
     * are more of them than there is real content, and importing them would
     * fill the new site with duplicates of its own pages.
     */
    public function testAutoDraftsAreNotImported(): void
    {
        $ids = array_map(
            static fn (MigrationRow $r): string => $r->sourceId,
            $this->rows((new WxrPostSource($this->reader(), 'post'))->rows()),
        );

        self::assertNotContains('43', $ids);
    }

    public function testPostRowsCarryTheirTermSlugsSeparately(): void
    {
        $rows = $this->rows((new WxrPostSource($this->reader(), 'post'))->rows());

        self::assertSame(['haberler'], $rows[0]->get('categorySlugs'));
        self::assertSame(['php'], $rows[0]->get('tagSlugs'));
    }

    public function testPostRowCarriesTheGmtPublishDate(): void
    {
        $rows = $this->rows((new WxrPostSource($this->reader(), 'post'))->rows());

        self::assertSame('2024-03-01 07:00:00', $rows[0]->get('publishedAt'));
    }

    /**
     * Counting posts would mean a second pass over a file this source exists to
     * read exactly once, so it says so rather than paying for a progress total.
     */
    public function testPostSourceDoesNotClaimToKnowItsSize(): void
    {
        self::assertNull((new WxrPostSource($this->reader(), 'post'))->count());
    }
}
