<?php

declare(strict_types=1);

namespace Modules\Blog\Aacp;

use App\Core\Aacp\SystemWidgetData;
use App\Core\Aacp\SystemWidgetProviderInterface;
use App\Repository\NodeRepository;

/**
 * AACP system widget: count of posts waiting to publish. Tagged only; core never hard-codes this class.
 */
final class ScheduledPostsWidgetProvider implements SystemWidgetProviderInterface
{
    private const NODE_TYPE = 'post';

    public function __construct(
        private readonly NodeRepository $nodeRepository,
    ) {
    }

    public function getWidget(): SystemWidgetData
    {
        $pendingCount = $this->nodeRepository->countPendingScheduledNodes(self::NODE_TYPE);

        return new SystemWidgetData(
            title: 'Blog — Zamanlanmış Yayın',
            value: $pendingCount,
            unit: null,
            description: $pendingCount > 0
                ? 'Yayın zamanını bekleyen blog yazısı. "blog.publish_scheduled" cron görevi çalıştığında otomatik yayına alınır.'
                : 'Bekleyen zamanlanmış yazı yok.',
            linkRoute: 'admin_posts_index',
            linkRouteParams: [],
        );
    }
}
