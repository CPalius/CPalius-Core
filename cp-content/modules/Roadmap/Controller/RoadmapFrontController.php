<?php

declare(strict_types=1);

namespace Modules\Roadmap\Controller;

use Modules\Roadmap\Entity\RoadmapEntry;
use Modules\Roadmap\Repository\RoadmapEntryRepository;
use Modules\Roadmap\Service\RoadmapFeedService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

#[Route('/roadmap')]
final class RoadmapFrontController extends AbstractController
{
    public function __construct(
        private readonly RoadmapFeedService $feedService,
        private readonly RoadmapEntryRepository $entryRepository,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route('', name: 'roadmap_index', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $locale = $request->getLocale();
        $status = $request->query->get('status');
        $page = $request->query->getInt('page', 1);

        $view = $this->feedService->buildPage(
            $locale,
            $page,
            \is_string($status) ? $status : null,
        );

        $totalPages = max(1, (int) ceil($view['total'] / max(1, $view['perPage'])));

        return $this->render('@Theme/roadmap/index.html.twig', [
            'nativeEntries' => $view['nativeEntries'],
            'moduleTimeline' => $view['moduleTimeline'],
            'moduleTunnelRows' => $view['moduleTunnelRows'],
            'blogItems' => $view['blogItems'],
            'forumItems' => $view['forumItems'],
            'recentCount' => $view['recentCount'],
            'statusFilter' => $view['statusFilter'],
            'page' => $view['page'],
            'perPage' => $view['perPage'],
            'total' => $view['total'],
            'totalPages' => $totalPages,
            'statuses' => [
                RoadmapEntry::STATUS_PLANNED,
                RoadmapEntry::STATUS_IN_PROGRESS,
                RoadmapEntry::STATUS_SHIPPED,
            ],
        ]);
    }

    #[Route('/{slug}', name: 'roadmap_show', methods: ['GET'], requirements: ['slug' => '[a-z0-9\-]+'])]
    public function show(Request $request, string $slug): Response
    {
        $locale = $request->getLocale();
        $entry = $this->entryRepository->findOneBySlugAndLocale($slug, $locale);

        if ($entry instanceof RoadmapEntry && $entry->isPubliclyVisible()) {
            $request->attributes->set('roadmap_entry', $entry);

            return $this->render('@Theme/roadmap/show.html.twig', [
                'entry' => $entry,
            ]);
        }

        return $this->resolveCrossLocale($slug, $locale);
    }

    /**
     * Same slug in another locale → redirect to published sibling, or a soft unavailable page.
     */
    private function resolveCrossLocale(string $slug, string $locale): Response
    {
        $source = $this->entryRepository->findOnePublicBySlug($slug);
        if (!$source instanceof RoadmapEntry) {
            return $this->renderUnavailable(null, $slug, $locale);
        }

        $groupId = $source->getTranslationGroupId();
        if ($groupId !== null) {
            $translation = $this->entryRepository->findTranslation($groupId, $locale);
            if ($translation instanceof RoadmapEntry && $translation->isPubliclyVisible()) {
                return $this->redirectToRoute('roadmap_show', [
                    '_locale' => $locale,
                    'slug' => $translation->getSlug(),
                ]);
            }
        }

        return $this->renderUnavailable($source, $slug, $locale);
    }

    private function renderUnavailable(?RoadmapEntry $source, string $requestedSlug, string $locale): Response
    {
        return $this->render(
            '@Theme/roadmap/unavailable.html.twig',
            [
                'sourceEntry' => $source,
                'requestedSlug' => $requestedSlug,
                'locale' => $locale,
                'heading' => $source instanceof RoadmapEntry
                    ? $this->translator->trans('site.roadmap.unavailable.translation_heading')
                    : $this->translator->trans('site.roadmap.unavailable.not_found_heading'),
            ],
            new Response('', Response::HTTP_NOT_FOUND),
        );
    }
}
