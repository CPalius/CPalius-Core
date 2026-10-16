<?php

declare(strict_types=1);

namespace Modules\Blog\Controller;

use App\Entity\Node;
use App\Repository\NodeRepository;
use Modules\Blog\Service\PostViewCounter;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Receives the read beacon the post page sends (see blog/show.html.twig). Public and CSRF-free on purpose:
 * it only adds one to a counter, it is de-duplicated per visitor, and crawlers (which do not run the page's
 * script anyway) are ignored by name as a second line.
 */
final class PostViewController
{
    private const PER_MINUTE = 6;

    private const BOT = '/bot|crawl|spider|slurp|preview|fetch|monitor|headless|curl|wget|python|java|go-http/i';

    public function __construct(
        private readonly NodeRepository $nodes,
        private readonly PostViewCounter $counter,
        private readonly CacheItemPoolInterface $cache,
    ) {
    }

    #[Route('/blog/okundu/{id}', name: 'blog_view_ping', methods: ['POST'], requirements: ['id' => '\d+'], priority: 2)]
    public function ping(Request $request, int $id): Response
    {
        $agent = (string) $request->headers->get('User-Agent', '');
        if ($agent === '' || preg_match(self::BOT, $agent) === 1) {
            return new Response('', Response::HTTP_NO_CONTENT);
        }

        if (!$this->withinRateLimit($id, (string) $request->getClientIp())) {
            return new Response('', Response::HTTP_NO_CONTENT);
        }

        $node = $this->nodes->find($id);
        if ($node instanceof Node && $node->getType() === 'post' && $node->getStatus() === Node::STATUS_PUBLISHED && $node->getDeletedAt() === null) {
            $this->counter->record($id, ($request->getClientIp() ?? '').'|'.$agent);
        }

        // 204 whatever happened: the page learns nothing about what the counter did.
        return new Response('', Response::HTTP_NO_CONTENT);
    }

    /** Fixed one-minute window per IP + post. ponytail: not atomic across workers; a few extra pings can slip through. */
    private function withinRateLimit(int $id, string $ip): bool
    {
        $item = $this->cache->getItem('cp.blog.viewrl.'.hash('xxh3', $id.'|'.$ip.'|'.intdiv(time(), 60)));
        $hits = (int) $item->get();
        if ($hits >= self::PER_MINUTE) {
            return false;
        }
        $this->cache->save($item->set($hits + 1)->expiresAfter(90));

        return true;
    }
}
