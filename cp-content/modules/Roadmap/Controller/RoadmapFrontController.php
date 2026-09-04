<?php

declare(strict_types=1);

namespace Modules\Roadmap\Controller;

use Modules\Roadmap\Entity\RoadmapEntry;
use Modules\Roadmap\Repository\RoadmapEntryRepository;
use Modules\Roadmap\Service\RoadmapFeedService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
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
        $entry = $this->entryRepository->findOneBySlugAndLocale($slug, $request->getLocale());
        if (!$entry instanceof RoadmapEntry || !$entry->isPubliclyVisible()) {
            throw new NotFoundHttpException($this->translator->trans('site.roadmap.error.not_found'));
        }

        return $this->render('@Theme/roadmap/show.html.twig', [
            'entry' => $entry,
        ]);
    }
}
