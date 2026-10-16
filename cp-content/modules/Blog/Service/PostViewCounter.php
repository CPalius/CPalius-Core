<?php

declare(strict_types=1);

namespace Modules\Blog\Service;

use Doctrine\DBAL\Connection;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

/**
 * "How many people read this post", for the Most read widget.
 *
 * Counted from the page itself (a small beacon the post template sends once per visitor), not from the
 * server request: public pages are served from the page cache, so a server-side counter would only see the
 * few requests that miss it. A visitor counts once per post per half hour, whatever they do with reload.
 */
final class PostViewCounter
{
    private const DEDUPE_SECONDS = 1800;

    public function __construct(
        private readonly Connection $connection,
        private readonly CacheInterface $cache,
    ) {
    }

    /**
     * @param string $visitor an opaque per-visitor string (hash of address + browser); never stored
     *
     * @return bool true when this visit was counted
     */
    public function record(int $nodeId, string $visitor): bool
    {
        $counted = false;
        $this->cache->get('cp.blog.view.'.hash('xxh3', $nodeId.'|'.$visitor), function (ItemInterface $item) use ($nodeId, &$counted): bool {
            $item->expiresAfter(self::DEDUPE_SECONDS);
            $counted = true;

            $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
            $this->connection->executeStatement(
                'INSERT INTO cp_blog_post_views (node_id, views, updated_at) VALUES (:id, 1, :now)
                 ON DUPLICATE KEY UPDATE views = views + 1, updated_at = :now',
                ['id' => $nodeId, 'now' => $now],
            );

            return true;
        });

        return $counted;
    }

    /**
     * Post ids, most read first. The caller filters (published, language) and trims, so ask for more than
     * is shown.
     *
     * @return list<int>
     */
    public function topIds(int $limit): array
    {
        try {
            $rows = $this->connection->fetchFirstColumn(
                'SELECT node_id FROM cp_blog_post_views WHERE views > 0 ORDER BY views DESC, updated_at DESC LIMIT '.max(1, min(200, $limit)),
            );
        } catch (\Throwable) {
            // The table does not exist yet (module upgrade pending): no ranking, not an error page.
            return [];
        }

        return array_map('intval', $rows);
    }
}
