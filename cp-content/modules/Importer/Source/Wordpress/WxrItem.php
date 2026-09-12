<?php

declare(strict_types=1);

namespace Modules\Importer\Source\Wordpress;

/**
 * One <item> from a WordPress export, still in WordPress's vocabulary.
 *
 * Kept as a typed object rather than the loose array the format parses into,
 * so the migrations that map it onto CPalius entities are checked rather than
 * hoping a key exists. Everything is a string because that is what XML holds;
 * interpretation (is "1" sticky, is "publish" published) belongs to the
 * migration, which knows what the destination means.
 */
final class WxrItem
{
    /**
     * @param list<array{name: string, slug: string, taxonomy: string}> $terms
     * @param array<string, string>                                     $meta
     * @param list<array<string, string>>                               $comments
     */
    public function __construct(
        public readonly string $postId,
        public readonly string $postType,
        public readonly string $status,
        public readonly string $title,
        public readonly string $slug,
        public readonly string $link,
        public readonly string $guid,
        public readonly string $creator,
        public readonly string $content,
        public readonly string $excerpt,
        public readonly string $dateGmt,
        public readonly string $date,
        public readonly string $parentId,
        public readonly string $menuOrder,
        public readonly string $isSticky,
        public readonly string $attachmentUrl,
        public readonly array $terms = [],
        public readonly array $meta = [],
        public readonly array $comments = [],
    ) {
    }

    /**
     * Terms in one taxonomy, as [slug => name].
     *
     * WordPress writes the taxonomy into the category element's domain
     * attribute: "category" and "post_tag" for the built-in two.
     *
     * @return array<string, string>
     */
    public function termsIn(string $taxonomy): array
    {
        $found = [];

        foreach ($this->terms as $term) {
            if ($term['taxonomy'] !== $taxonomy) {
                continue;
            }

            $slug = $term['slug'] !== '' ? $term['slug'] : $term['name'];

            if ($slug !== '') {
                $found[$slug] = $term['name'];
            }
        }

        return $found;
    }

    /**
     * The GMT date, which is the one worth trusting: post_date is in whatever
     * timezone the old site was configured for at the time, and that setting is
     * not in the export.
     */
    public function publishedAt(): ?\DateTimeImmutable
    {
        foreach ([$this->dateGmt, $this->date] as $raw) {
            $value = trim($raw);

            // WordPress writes this for "never published".
            if ($value === '' || str_starts_with($value, '0000-00-00')) {
                continue;
            }

            try {
                return new \DateTimeImmutable($value.' UTC');
            } catch (\Exception) {
                continue;
            }
        }

        return null;
    }
}
