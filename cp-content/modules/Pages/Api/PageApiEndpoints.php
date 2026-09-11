<?php

declare(strict_types=1);

namespace Modules\Pages\Api;

use App\Core\Api\Attribute\CpApi;
use App\Repository\NodeRepository;
use Modules\Pages\Controller\Admin\PageAdminController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

final class PageApiEndpoints
{
    public function __construct(
        private readonly NodeRepository $nodeRepository,
    ) {
    }

    #[CpApi(path: '/pages', methods: ['GET'], public: true)]
    public function listPages(Request $request): JsonResponse
    {
        $locale = (string) ($request->query->get('locale') ?? 'tr');
        $limit = min(50, max(1, $request->query->getInt('limit', 20)));

        $pages = $this->nodeRepository->findPublishedByTypeAndLocale(PageAdminController::NODE_TYPE, $locale, $limit);

        return new JsonResponse([
            'data' => array_map(
                static fn ($page): array => [
                    'id' => $page->getId(),
                    'title' => $page->getTitle(),
                    'slug' => $page->getSlug(),
                    'locale' => $page->getLocale(),
                ],
                $pages,
            ),
        ]);
    }
}
