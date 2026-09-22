<?php

declare(strict_types=1);

namespace App\Controller;

use App\Core\Search\GlobalSearchService;
use App\Core\Search\SearchGroup;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Site-wide front search. Header form posts here; modules contribute via SearchProviderInterface.
 */
final class SearchController extends AbstractController
{
    public function __construct(
        private readonly GlobalSearchService $globalSearchService,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route(
        '/{_locale}/ara',
        name: 'site_search',
        requirements: ['_locale' => '%cpalius.locales_pattern%'],
        methods: ['GET'],
    )]
    public function search(Request $request): Response
    {
        $term = trim((string) $request->query->get('q', ''));
        $locale = $request->getLocale();
        $groups = $term !== '' ? $this->globalSearchService->search($term, $locale) : [];

        $totalHits = 0;
        foreach ($groups as $group) {
            $totalHits += $group->total;
        }

        return $this->render('@Theme/search/index.html.twig', [
            'term' => $term,
            'groups' => $groups,
            'totalHits' => $totalHits,
        ]);
    }

    #[Route(
        '/{_locale}/ara/canli',
        name: 'site_search_live',
        requirements: ['_locale' => '%cpalius.locales_pattern%'],
        methods: ['GET'],
    )]
    public function live(Request $request): JsonResponse
    {
        $term = trim((string) $request->query->get('q', ''));
        if (mb_strlen($term) > 80) {
            $term = mb_substr($term, 0, 80);
        }

        if (mb_strlen($term) < 2) {
            return new JsonResponse(['ok' => true, 'term' => $term, 'total' => 0, 'groups' => []], 200, [
                'Cache-Control' => 'private, no-store',
            ]);
        }

        $groups = $this->globalSearchService->search($term, $request->getLocale(), 5);

        return new JsonResponse([
            'ok' => true,
            'term' => $term,
            'total' => $this->hitCount($groups),
            'groups' => $this->serializeGroups($groups),
        ], 200, [
            'Cache-Control' => 'private, no-store',
        ]);
    }

    /**
     * @param list<SearchGroup> $groups
     */
    private function hitCount(array $groups): int
    {
        $total = 0;
        foreach ($groups as $group) {
            $total += $group->total;
        }

        return $total;
    }

    /**
     * @param list<SearchGroup> $groups
     *
     * @return list<array{key: string, label: string, icon: string, moreUrl: ?string, hits: list<array{title: string, url: string, excerpt: ?string}>}>
     */
    private function serializeGroups(array $groups): array
    {
        $out = [];
        foreach ($groups as $group) {
            $hits = [];
            foreach ($group->hits as $hit) {
                $hits[] = [
                    'title' => $hit->title,
                    'url' => $hit->url,
                    'excerpt' => $hit->excerpt,
                ];
            }

            $out[] = [
                'key' => $group->key,
                'label' => $this->translator->trans($group->label),
                'icon' => $group->icon,
                'moreUrl' => $group->moreUrl,
                'hits' => $hits,
            ];
        }

        return $out;
    }
}
