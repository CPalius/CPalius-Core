<?php

declare(strict_types=1);

namespace Modules\Seo\Document;

/**
 * Resolved SEO payload for one front-office request.
 */
final class SeoDocument
{
    /**
     * @param list<array{name: string, url: string}> $breadcrumbs
     * @param list<string> $images
     * @param array<string, mixed> $schemaExtra extra JSON-LD node merged into @graph
     */
    public function __construct(
        public string $headline = '',
        public string $description = '',
        public string $canonicalPath = '',
        public string $ogType = 'website',
        public string $contentKind = 'page',
        public string $schemaType = 'WebPage',
        public string $robots = 'index, follow',
        public array $breadcrumbs = [],
        public array $images = [],
        public ?string $videoUrl = null,
        public ?string $videoTitle = null,
        public ?string $authorName = null,
        public ?\DateTimeInterface $publishedAt = null,
        public ?\DateTimeInterface $modifiedAt = null,
        public ?string $locale = null,
        public array $schemaExtra = [],
        public ?string $prevUrl = null,
        public ?string $nextUrl = null,
        public bool $forceNoindex = false,
    ) {
    }
}
