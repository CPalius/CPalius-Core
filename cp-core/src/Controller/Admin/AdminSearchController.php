<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Core\Search\Admin\AdminSearchService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * JSON endpoint behind the AACP and Studio header search. Each panel has its own URL so
 * the existing firewall rules for /aacp and /admin decide who may call it.
 */
final class AdminSearchController extends AbstractController
{
    private const MIN_CHARS = 2;
    private const MAX_CHARS = 80;

    public function __construct(
        private readonly AdminSearchService $search,
    ) {
    }

    #[Route('/aacp/search/live', name: 'aacp_search_live', methods: ['GET'])]
    public function aacp(Request $request): JsonResponse
    {
        return $this->respond($request, 'aacp');
    }

    #[Route('/admin/search/live', name: 'admin_search_live', methods: ['GET'])]
    public function studio(Request $request): JsonResponse
    {
        return $this->respond($request, 'studio');
    }

    private function respond(Request $request, string $panel): JsonResponse
    {
        $term = mb_substr(trim((string) $request->query->get('q', '')), 0, self::MAX_CHARS);
        $groups = mb_strlen($term) >= self::MIN_CHARS ? $this->search->search($term, $panel) : [];

        return new JsonResponse(['term' => $term, 'groups' => $groups], 200, ['Cache-Control' => 'private, no-store']);
    }
}
