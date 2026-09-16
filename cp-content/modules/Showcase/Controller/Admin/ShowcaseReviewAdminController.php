<?php

declare(strict_types=1);

namespace Modules\Showcase\Controller\Admin;

use App\Core\Annotation\CpAdminMenu;
use App\Core\Pagination\Paginator;
use Modules\Showcase\Entity\ShowcaseReview;
use Modules\Showcase\Repository\ShowcaseReviewRepository;
use Modules\Showcase\Service\ShowcaseReviewService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Review moderation queue. Approving or rejecting recomputes the item's rating
 * average, so a removed review actually changes the number visitors see.
 */
#[Route('/admin/showcase/reviews', name: 'admin_showcase_reviews_')]
#[IsGranted('showcase.review.moderate')]
final class ShowcaseReviewAdminController extends AbstractController
{
    private const CSRF_TOKEN = 'admin_showcase_review';
    private const PER_PAGE = 25;

    public function __construct(
        private readonly ShowcaseReviewRepository $reviews,
        private readonly ShowcaseReviewService $reviewService,
        private readonly Paginator $paginator,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route('', name: 'index', methods: ['GET'])]
    #[CpAdminMenu(label: 'showcase.menu.reviews', icon: 'heroicons:star', panel: 'studio', priority: 42, capability: 'showcase.review.moderate', parent: 'admin_showcase_dashboard')]
    public function index(Request $request): Response
    {
        $result = $this->paginator->paginate(
            $this->reviews->createPendingQueryBuilder(),
            $request->query->getInt('page', 1),
            self::PER_PAGE,
        );

        return $this->render('@ShowcaseModule/admin/items/reviews.html.twig', [
            'result' => $result,
            'csrfToken' => self::CSRF_TOKEN,
        ]);
    }

    #[Route('/{id}/{action}', name: 'moderate', methods: ['POST'], requirements: ['id' => '\d+', 'action' => 'approve|reject|delete'])]
    public function moderate(int $id, string $action, Request $request): Response
    {
        $review = $this->reviews->find($id);

        if (!$review instanceof ShowcaseReview) {
            throw new NotFoundHttpException();
        }

        if (!$this->isCsrfTokenValid(self::CSRF_TOKEN, (string) $request->request->get('_token'))) {
            throw new BadRequestHttpException($this->translator->trans('showcase.error.invalid_csrf'));
        }

        match ($action) {
            'approve' => $this->reviewService->approve($review),
            'reject' => $this->reviewService->reject($review),
            default => $this->reviewService->delete($review),
        };

        $this->addFlash('success', $this->translator->trans('showcase.reviews.flash.moderated'));

        return $this->redirectToRoute('admin_showcase_reviews_index');
    }
}
