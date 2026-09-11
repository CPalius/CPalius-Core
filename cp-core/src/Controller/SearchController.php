<?php

declare(strict_types=1);

namespace App\Controller;

use App\Core\Search\GlobalSearchService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Site-wide front search. Header form posts here; modules contribute via SearchProviderInterface.
 */
final class SearchController extends AbstractController
{
    public function __construct(
        private readonly GlobalSearchService $globalSearchService,
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
}
