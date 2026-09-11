<?php

declare(strict_types=1);

namespace Modules\Pages\Controller;

use App\Entity\Node;
use App\Repository\NodeRepository;
use Modules\Pages\Controller\Admin\PageAdminController;
use Modules\Pages\PageReservedSlugs;
use Modules\Pages\Service\PagePresentationService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Front render for Node type "page" at /{_locale}/{slug}.
 * Negative priority so static module routes (blog, forum, roadmap) always win.
 */
final class PageFrontController extends AbstractController
{
    public function __construct(
        private readonly NodeRepository $nodeRepository,
        private readonly PagePresentationService $presentationService,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route(
        '/{slug}',
        name: 'page_show',
        requirements: ['slug' => '^(?!blog$|forum$|forums$|roadmap$|ara$|aacp$|admin$|api$|hesap$|cron$|whitepaper$|login$|logout$|media$|themes$|uploads$|assets$)[a-z0-9]+(?:-[a-z0-9]+)*'],
        priority: -10,
        methods: ['GET'],
    )]
    public function show(Request $request, string $slug): Response
    {
        if (PageReservedSlugs::isReserved($slug)) {
            throw $this->createNotFoundException();
        }

        $locale = $request->getLocale();
        $node = $this->nodeRepository->findOnePublishedBySlugAndLocale($slug, $locale);

        if (!$node instanceof Node || $node->getType() !== PageAdminController::NODE_TYPE) {
            return $this->resolveCrossLocalePage($slug, $locale);
        }

        $request->attributes->set('static_page', $node);

        return $this->render('@Theme/page/show.html.twig', $this->presentationService->viewData($node));
    }

    private function resolveCrossLocalePage(string $slug, string $locale): Response
    {
        $source = $this->nodeRepository->findOnePublishedBySlug($slug, PageAdminController::NODE_TYPE);
        if (!$source instanceof Node) {
            return $this->renderPageUnavailable(null, $slug, $locale);
        }

        $groupId = $source->getTranslationGroupId();
        if ($groupId !== null) {
            $translation = $this->nodeRepository->findTranslation($groupId, $locale);
            if (
                $translation instanceof Node
                && $translation->getType() === PageAdminController::NODE_TYPE
                && $translation->getStatus() === Node::STATUS_PUBLISHED
                && $translation->getDeletedAt() === null
            ) {
                return $this->redirectToRoute('page_show', [
                    '_locale' => $locale,
                    'slug' => $translation->getSlug(),
                ]);
            }
        }

        return $this->renderPageUnavailable($source, $slug, $locale);
    }

    private function renderPageUnavailable(?Node $source, string $requestedSlug, string $locale): Response
    {
        return $this->render(
            '@Theme/page/unavailable.html.twig',
            [
                'sourcePage' => $source,
                'requestedSlug' => $requestedSlug,
                'locale' => $locale,
                'heading' => $source instanceof Node
                    ? $this->translator->trans('pages.front.unavailable.translation_heading')
                    : $this->translator->trans('pages.front.unavailable.not_found_heading'),
            ],
            new Response('', Response::HTTP_NOT_FOUND),
        );
    }
}
