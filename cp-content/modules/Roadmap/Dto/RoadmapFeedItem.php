<?php

declare(strict_types=1);

namespace Modules\Roadmap\Dto;

/**
 * Unified feed row so native, blog, and forum sources share one Twig shape.
 */
final class RoadmapFeedItem
{
    public const SOURCE_NATIVE = 'native';
    public const SOURCE_BLOG = 'blog';
    public const SOURCE_FORUM = 'forum';

    public function __construct(
        public readonly string $source,
        public readonly string $title,
        public readonly string $excerpt,
        public readonly string $url,
        public readonly \DateTimeImmutable $occurredAt,
        public readonly ?string $status = null,
        public readonly ?string $badge = null,
        public readonly ?string $icon = null,
        public readonly ?string $versionLabel = null,
        public readonly ?string $kind = null,
        public readonly ?string $slug = null,
        public readonly ?string $authorName = null,
        public readonly ?string $authorAvatarUrl = null,
        public readonly ?int $authorId = null,
    ) {
    }
}
