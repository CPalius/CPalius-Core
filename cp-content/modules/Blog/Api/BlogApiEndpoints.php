<?php

declare(strict_types=1);

namespace Modules\Blog\Api;

use App\Core\Api\Attribute\CpApi;
use App\Repository\NodeRepository;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Phase 7B #[CpApi] sample endpoint under /api/*. public: true skips X-CP-API-KEY.
 */
final class BlogApiEndpoints
{
    public function __construct(
        private readonly NodeRepository $nodeRepository,
    ) {
    }

    #[CpApi(path: '/blog/posts', methods: ['GET'], public: true)]
    public function listPosts(Request $request): JsonResponse
    {
        $locale = (string) ($request->query->get('locale') ?? 'tr');
        $limit = min(50, max(1, $request->query->getInt('limit', 10)));

        $posts = $this->nodeRepository->findPublishedByTypeAndLocale('post', $locale, $limit);

        return new JsonResponse([
            'data' => array_map(
                static fn ($post): array => [
                    'id' => $post->getId(),
                    'title' => $post->getTitle(),
                ],
                $posts,
            ),
        ]);
    }
}
