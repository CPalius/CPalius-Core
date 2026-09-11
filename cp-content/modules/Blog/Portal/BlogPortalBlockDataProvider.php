<?php

declare(strict_types=1);

namespace Modules\Blog\Portal;

use App\Core\Portal\PortalBlockDataProviderInterface;
use App\Repository\NodeRepository;

final class BlogPortalBlockDataProvider implements PortalBlockDataProviderInterface
{
    private const NODE_TYPE_POST = 'post';

    public function __construct(
        private readonly NodeRepository $nodeRepository,
    ) {
    }

    public function supports(string $blockId): bool
    {
        return $blockId === 'latest_blog_posts';
    }

    public function provide(string $blockId, array $block, string $locale): ?array
    {
        $limit = max(1, (int) ($block['limit'] ?? 5));
        $items = $this->nodeRepository->findPublishedByTypeAndLocale(self::NODE_TYPE_POST, $locale, $limit);

        return $items === [] ? null : ['items' => $items];
    }
}
