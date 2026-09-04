<?php

declare(strict_types=1);

namespace Modules\Roadmap\Contract;

use Modules\Roadmap\Dto\RoadmapFeedItem;

/**
 * Forum topics for the roadmap feed when Forum is enabled; otherwise Roadmap uses an empty list.
 */
interface RoadmapForumFeedProviderInterface
{
    /**
     * @param list<int> $userIds
     *
     * @return list<RoadmapFeedItem>
     */
    public function fetchTopics(string $locale, int $sectionId, int $limit, array $userIds = []): array;
}
