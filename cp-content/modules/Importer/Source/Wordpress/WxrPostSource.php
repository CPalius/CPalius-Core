<?php

declare(strict_types=1);

namespace Modules\Importer\Source\Wordpress;

use App\Core\Migrate\MigrationRow;
use App\Core\Migrate\MigrationSourceInterface;

/**
 * Items of one WordPress post type, still in WordPress terms.
 *
 * Filtering happens here rather than in the reader so a single pass over the
 * file can serve "posts" and "pages" as two migrations with two maps — which
 * is what lets an operator re-run one without touching the other.
 *
 * Revisions, auto-drafts and the trash are dropped. WordPress keeps every
 * autosave as a post row of its own, and an export of a long-lived site
 * contains far more of them than real content; importing them would fill the
 * new site with duplicates of its own pages.
 */
final class WxrPostSource implements MigrationSourceInterface
{
    private const NEVER_IMPORT_STATUS = ['auto-draft', 'trash', 'inherit'];

    public function __construct(
        private readonly WxrReader $reader,
        private readonly string $postType = 'post',
    ) {
    }

    public function describe(): string
    {
        return sprintf('WordPress "%s" items in %s', $this->postType, $this->reader->path());
    }

    public function rows(): iterable
    {
        foreach ($this->reader->items() as $item) {
            if ($item->postType !== $this->postType) {
                continue;
            }

            if (\in_array($item->status, self::NEVER_IMPORT_STATUS, true)) {
                continue;
            }

            if ($item->postId === '') {
                continue;
            }

            yield new MigrationRow($item->postId, [
                'title' => $item->title,
                'slug' => $item->slug,
                'content' => $item->content,
                'excerpt' => $item->excerpt,
                'status' => $item->status,
                'creator' => $item->creator,
                'publishedAt' => $item->publishedAt()?->format('Y-m-d H:i:s') ?? '',
                'link' => $item->link,
                'guid' => $item->guid,
                'parentId' => $item->parentId,
                'menuOrder' => $item->menuOrder,
                'isSticky' => $item->isSticky,
                'categorySlugs' => array_keys($item->termsIn(WxrTermSource::TAXONOMY_CATEGORY)),
                'tagSlugs' => array_keys($item->termsIn(WxrTermSource::TAXONOMY_TAG)),
                'meta' => $item->meta,
            ]);
        }
    }

    /**
     * Unknowable without reading the whole export, which is exactly what this
     * source exists to avoid doing twice.
     */
    public function count(): ?int
    {
        return null;
    }
}
