<?php

declare(strict_types=1);

namespace Modules\Importer\Source\Wordpress;

/**
 * Streams a WordPress eXtended RSS export.
 *
 * WHY THIS IS WRITTEN FROM SCRATCH AND WHY IT STREAMS
 * WordPress's own importer loads the entire export into a DOMDocument before
 * it looks at anything. That is the direct cause of the failure everybody who
 * has migrated a real site has met: a 300 MB export dies on memory_limit, or
 * the request times out half way and leaves a partial import nobody can
 * resume. XMLReader pulls one element at a time, so the memory cost is one
 * <item> no matter how large the file, and the core runner's map makes the
 * partial run resumable.
 *
 * The WXR shape this reads is a documented file format, not borrowed code:
 *
 *   rss > channel
 *     wp:wxr_version, wp:base_site_url, wp:base_blog_url
 *     wp:author   author_id, author_login, author_email, author_display_name,
 *                 author_first_name, author_last_name
 *     wp:category term_id, category_nicename, category_parent, cat_name,
 *                 category_description
 *     wp:tag      term_id, tag_slug, tag_name, tag_description
 *     wp:term     term_id, term_taxonomy, term_slug, term_parent, term_name,
 *                 term_description
 *     item        title, link, guid, dc:creator, content:encoded,
 *                 excerpt:encoded, wp:post_id, wp:post_date, wp:post_date_gmt,
 *                 wp:post_name, wp:status, wp:post_type, wp:post_parent,
 *                 wp:menu_order, wp:is_sticky, wp:attachment_url,
 *                 category[domain,nicename], wp:postmeta, wp:comment
 *
 * Namespaces are matched by URI rather than by the "wp:" prefix, because a
 * prefix is only a local alias: an export written by a different tool may bind
 * the same namespace to a different prefix, and prefix matching would then read
 * an otherwise valid file as empty.
 */
final class WxrReader
{
    public const NS_WP_11 = 'http://wordpress.org/export/1.1/';
    public const NS_WP_12 = 'http://wordpress.org/export/1.2/';
    public const NS_WP_10 = 'http://wordpress.org/export/1.0/';
    public const NS_EXCERPT = 'http://wordpress.org/export/1.1/excerpt/';
    public const NS_CONTENT = 'http://purl.org/rss/1.0/modules/content/';
    public const NS_DC = 'http://purl.org/dc/elements/1.1/';

    public function __construct(
        private readonly string $path,
    ) {
    }

    public function path(): string
    {
        return $this->path;
    }

    /**
     * Authors declared at channel level, keyed by login.
     *
     * @return iterable<array<string, string>>
     */
    public function authors(): iterable
    {
        yield from $this->channelElements('author', [
            'author_id', 'author_login', 'author_email',
            'author_display_name', 'author_first_name', 'author_last_name',
        ]);
    }

    /**
     * @return iterable<array<string, string>>
     */
    public function categories(): iterable
    {
        yield from $this->channelElements('category', [
            'term_id', 'category_nicename', 'category_parent', 'cat_name', 'category_description',
        ]);
    }

    /**
     * @return iterable<array<string, string>>
     */
    public function tags(): iterable
    {
        yield from $this->channelElements('tag', [
            'term_id', 'tag_slug', 'tag_name', 'tag_description',
        ]);
    }

    /**
     * Custom taxonomy terms (wp:term), which carry their taxonomy name.
     *
     * @return iterable<array<string, string>>
     */
    public function terms(): iterable
    {
        yield from $this->channelElements('term', [
            'term_id', 'term_taxonomy', 'term_slug', 'term_parent', 'term_name', 'term_description',
        ]);
    }

    /**
     * Every <item>, whatever its post type — posts, pages, attachments, nav
     * menu items. Filtering is the migration's job, not the reader's: a reader
     * that silently dropped post types would make "my pages did not come over"
     * a mystery inside the parser.
     *
     * @return iterable<WxrItem>
     */
    public function items(): iterable
    {
        $reader = $this->open();

        try {
            while ($reader->read()) {
                if ($reader->nodeType !== \XMLReader::ELEMENT || $reader->localName !== 'item') {
                    continue;
                }

                $xml = $reader->readOuterXml();

                if ($xml === '') {
                    continue;
                }

                $element = $this->parseFragment($xml);

                if ($element === null) {
                    continue;
                }

                yield $this->toItem($element);

                // Do not descend into the item we just consumed.
                $reader->next();
            }
        } finally {
            $reader->close();
        }
    }

    /**
     * The wxr_version the file declares, or null when it declares none — which
     * is the cheapest way to tell a WXR file from some other RSS document
     * before an operator watches an import produce nothing.
     */
    public function version(): ?string
    {
        $reader = $this->open();

        try {
            while ($reader->read()) {
                if ($reader->nodeType !== \XMLReader::ELEMENT) {
                    continue;
                }

                if ($reader->localName === 'wxr_version' && $this->isWordpressNamespace($reader->namespaceURI)) {
                    return trim((string) $reader->readString());
                }

                // The version sits early in <channel>; once items start there
                // is no point reading the rest of a large file.
                if ($reader->localName === 'item') {
                    return null;
                }
            }
        } finally {
            $reader->close();
        }

        return null;
    }

    public function assertReadable(): void
    {
        if (!is_file($this->path) || !is_readable($this->path)) {
            throw new \RuntimeException(sprintf('WXR file "%s" does not exist or cannot be read.', $this->path));
        }

        if ($this->version() === null) {
            throw new \RuntimeException(sprintf('File "%s" declares no wp:wxr_version, so it is not a WordPress export. Export from Tools -> Export in wp-admin.', $this->path));
        }
    }

    /**
     * @param list<string> $fields
     *
     * @return iterable<array<string, string>>
     */
    private function channelElements(string $name, array $fields): iterable
    {
        $reader = $this->open();

        try {
            while ($reader->read()) {
                if ($reader->nodeType !== \XMLReader::ELEMENT || $reader->localName !== $name) {
                    continue;
                }

                if (!$this->isWordpressNamespace($reader->namespaceURI)) {
                    continue;
                }

                $xml = $reader->readOuterXml();
                // These elements are themselves in the WordPress namespace, so
                // the fragment's root child has to be looked for there.
                $element = $xml === '' ? null : $this->parseFragment($xml, self::NS_WP_11);

                if ($element === null) {
                    continue;
                }

                $row = [];

                foreach ($fields as $field) {
                    $row[$field] = $this->firstValue($element, $field);
                }

                yield $row;

                $reader->next();
            }
        } finally {
            $reader->close();
        }
    }

    private function toItem(\SimpleXMLElement $element): WxrItem
    {
        $wp = $this->wordpressChildren($element);
        $content = $element->children(self::NS_CONTENT);
        $excerpt = $element->children(self::NS_EXCERPT);
        $dc = $element->children(self::NS_DC);

        $terms = [];
        foreach ($element->category as $category) {
            $attributes = $category->attributes();

            $terms[] = [
                'name' => (string) $category,
                'slug' => (string) ($attributes['nicename'] ?? ''),
                'taxonomy' => (string) ($attributes['domain'] ?? ''),
            ];
        }

        $meta = [];
        $comments = [];

        if ($wp !== null) {
            foreach ($wp->postmeta as $row) {
                $key = (string) ($row->meta_key ?? '');

                if ($key !== '') {
                    $meta[$key] = (string) ($row->meta_value ?? '');
                }
            }

            foreach ($wp->comment as $row) {
                $comments[] = [
                    'comment_id' => (string) ($row->comment_id ?? ''),
                    'comment_author' => (string) ($row->comment_author ?? ''),
                    'comment_author_email' => (string) ($row->comment_author_email ?? ''),
                    'comment_author_url' => (string) ($row->comment_author_url ?? ''),
                    'comment_date_gmt' => (string) ($row->comment_date_gmt ?? ''),
                    'comment_content' => (string) ($row->comment_content ?? ''),
                    'comment_approved' => (string) ($row->comment_approved ?? ''),
                    'comment_type' => (string) ($row->comment_type ?? ''),
                    'comment_parent' => (string) ($row->comment_parent ?? ''),
                    'comment_user_id' => (string) ($row->comment_user_id ?? ''),
                ];
            }
        }

        return new WxrItem(
            postId: $wp === null ? '' : (string) ($wp->post_id ?? ''),
            postType: $wp === null ? '' : (string) ($wp->post_type ?? ''),
            status: $wp === null ? '' : (string) ($wp->status ?? ''),
            title: (string) $element->title,
            slug: $wp === null ? '' : (string) ($wp->post_name ?? ''),
            link: (string) $element->link,
            guid: (string) $element->guid,
            creator: (string) ($dc->creator ?? ''),
            content: (string) ($content->encoded ?? ''),
            excerpt: (string) ($excerpt->encoded ?? ''),
            dateGmt: $wp === null ? '' : (string) ($wp->post_date_gmt ?? ''),
            date: $wp === null ? '' : (string) ($wp->post_date ?? ''),
            parentId: $wp === null ? '' : (string) ($wp->post_parent ?? ''),
            menuOrder: $wp === null ? '' : (string) ($wp->menu_order ?? ''),
            isSticky: $wp === null ? '' : (string) ($wp->is_sticky ?? ''),
            attachmentUrl: $wp === null ? '' : (string) ($wp->attachment_url ?? ''),
            terms: $terms,
            meta: $meta,
            comments: $comments,
        );
    }

    /**
     * Children in whichever WordPress export namespace this file uses.
     */
    private function wordpressChildren(\SimpleXMLElement $element): ?\SimpleXMLElement
    {
        foreach ([self::NS_WP_12, self::NS_WP_11, self::NS_WP_10] as $namespace) {
            $children = $element->children($namespace);

            if ($children->count() > 0) {
                return $children;
            }
        }

        return null;
    }

    private function firstValue(\SimpleXMLElement $element, string $field): string
    {
        foreach ([self::NS_WP_12, self::NS_WP_11, self::NS_WP_10] as $namespace) {
            $children = $element->children($namespace);

            if (isset($children->{$field})) {
                return trim((string) $children->{$field});
            }
        }

        return isset($element->{$field}) ? trim((string) $element->{$field}) : '';
    }

    private function isWordpressNamespace(?string $uri): bool
    {
        return \in_array($uri, [self::NS_WP_10, self::NS_WP_11, self::NS_WP_12], true);
    }

    /**
     * Parses one element in isolation.
     *
     * The fragment is wrapped in a root that redeclares every namespace, since
     * an element lifted out of its document no longer has the prefixes the
     * channel declared and would otherwise parse as unqualified.
     */
    private function parseFragment(string $xml, ?string $namespace = null): ?\SimpleXMLElement
    {
        $wrapped = sprintf(
            '<?xml version="1.0" encoding="UTF-8"?><cpwrap xmlns:wp="%s" xmlns:excerpt="%s" xmlns:content="%s" xmlns:dc="%s">%s</cpwrap>',
            self::NS_WP_11,
            self::NS_EXCERPT,
            self::NS_CONTENT,
            self::NS_DC,
            $xml,
        );

        $previous = libxml_use_internal_errors(true);

        try {
            $root = simplexml_load_string($wrapped, \SimpleXMLElement::class, \LIBXML_NOCDATA | \LIBXML_NONET);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        if ($root === false) {
            return null;
        }

        // children() without an argument only returns children in the default
        // namespace, so a <wp:author> fragment would come back as "no children"
        // and the row would vanish without an error. Ask in the element's own
        // namespace first, then fall back for unqualified elements like <item>.
        foreach ([$namespace, null] as $candidate) {
            $children = $candidate === null ? $root->children() : $root->children($candidate);

            foreach ($children as $child) {
                return $child;
            }
        }

        return null;
    }

    private function open(): \XMLReader
    {
        if (!is_file($this->path) || !is_readable($this->path)) {
            throw new \RuntimeException(sprintf('WXR file "%s" does not exist or cannot be read.', $this->path));
        }

        if (WordpressOrigin::looksLikeSqlDump($this->path)) {
            throw new \RuntimeException('That file is a SQL dump, not a WordPress WXR export. Choose it as the SQL dump (or fill in the remote database), not as the WXR file.');
        }

        // LIBXML_NONET refuses network fetches for external entities, and no
        // DTD is loaded: an export is a file from somewhere else, and parsing
        // one must not become a way to make this server issue requests or read
        // local files (XXE).
        $reader = \XMLReader::open($this->path, 'UTF-8', \LIBXML_NONET | \LIBXML_NOCDATA);

        if ($reader === false) {
            throw new \RuntimeException(sprintf('Could not open WXR file "%s" for reading.', $this->path));
        }

        return $reader;
    }
}
