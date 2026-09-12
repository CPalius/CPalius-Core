<?php

declare(strict_types=1);

namespace Modules\Importer\Source\Wordpress;

use App\Core\Migrate\MigrationRow;
use App\Core\Migrate\MigrationSourceInterface;

/**
 * Categories or tags from a WordPress export.
 *
 * WordPress writes its two built-in taxonomies in their own elements
 * (wp:category, wp:tag) and everything else in wp:term with the taxonomy
 * named inside. Both shapes are normalised here to one row, so the migration
 * and the destination do not each have to know the difference.
 *
 * Rows are keyed by the WordPress term slug, not term_id. The slug is what an
 * <item> references when it lists its categories, so keying on it is what lets
 * a post find its terms through the map. Where the export gives a parent as a
 * slug (categories) it is carried through; wp:term gives a parent slug too.
 */
final class WxrTermSource implements MigrationSourceInterface
{
    public const TAXONOMY_CATEGORY = 'category';
    public const TAXONOMY_TAG = 'post_tag';

    public function __construct(
        private readonly WxrReader $reader,
        private readonly string $taxonomy,
    ) {
    }

    public function describe(): string
    {
        return sprintf('WordPress "%s" terms in %s', $this->taxonomy, $this->reader->path());
    }

    public function rows(): iterable
    {
        if ($this->taxonomy === self::TAXONOMY_CATEGORY) {
            yield from $this->categories();
        } elseif ($this->taxonomy === self::TAXONOMY_TAG) {
            yield from $this->tags();
        }

        yield from $this->customTerms();
    }

    public function count(): int
    {
        return iterator_count($this->rows());
    }

    /**
     * @return iterable<MigrationRow>
     */
    private function categories(): iterable
    {
        foreach ($this->reader->categories() as $row) {
            $slug = $this->slug($row['category_nicename'] ?? '', $row['cat_name'] ?? '');

            if ($slug === null) {
                continue;
            }

            yield new MigrationRow($slug, [
                'name' => $row['cat_name'] ?? $slug,
                'slug' => $slug,
                'description' => $row['category_description'] ?? '',
                'parentSlug' => trim($row['category_parent'] ?? ''),
                'wpTermId' => $row['term_id'] ?? '',
            ]);
        }
    }

    /**
     * @return iterable<MigrationRow>
     */
    private function tags(): iterable
    {
        foreach ($this->reader->tags() as $row) {
            $slug = $this->slug($row['tag_slug'] ?? '', $row['tag_name'] ?? '');

            if ($slug === null) {
                continue;
            }

            yield new MigrationRow($slug, [
                'name' => $row['tag_name'] ?? $slug,
                'slug' => $slug,
                'description' => $row['tag_description'] ?? '',
                'parentSlug' => '',
                'wpTermId' => $row['term_id'] ?? '',
            ]);
        }
    }

    /**
     * wp:term entries whose taxonomy matches, which is how a custom taxonomy —
     * and, in some exports, the built-in two as well — is written.
     *
     * @return iterable<MigrationRow>
     */
    private function customTerms(): iterable
    {
        foreach ($this->reader->terms() as $row) {
            if (trim($row['term_taxonomy'] ?? '') !== $this->taxonomy) {
                continue;
            }

            $slug = $this->slug($row['term_slug'] ?? '', $row['term_name'] ?? '');

            if ($slug === null) {
                continue;
            }

            yield new MigrationRow($slug, [
                'name' => $row['term_name'] ?? $slug,
                'slug' => $slug,
                'description' => $row['term_description'] ?? '',
                'parentSlug' => trim($row['term_parent'] ?? ''),
                'wpTermId' => $row['term_id'] ?? '',
            ]);
        }
    }

    /**
     * Falls back to the name when a slug is absent: an unslugged term is still
     * a term, and dropping it would lose every post filed under it.
     */
    private function slug(string $slug, string $name): ?string
    {
        $slug = trim($slug);

        if ($slug !== '') {
            return $slug;
        }

        $name = trim($name);

        return $name === '' ? null : $name;
    }
}
