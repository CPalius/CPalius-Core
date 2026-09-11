<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Core\Module\ModuleContributionCatalog;
use App\Core\Revision\Entity\NodeRevision;
use App\Core\Revision\Repository\NodeRevisionRepository;
use App\Core\Revision\RevisionManager;
use App\Entity\Node;
use App\Entity\User;
use App\Repository\NodeRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

/**
 * Generic revision history for any Node, regardless of which module edits it.
 */
final class AACPContentRevisionController
{
    public function __construct(
        private readonly Environment $twig,
        private readonly CsrfTokenManagerInterface $csrfTokenManager,
        private readonly TranslatorInterface $translator,
        private readonly NodeRepository $nodes,
        private readonly NodeRevisionRepository $revisionRepository,
        private readonly RevisionManager $revisions,
        private readonly EntityManagerInterface $entityManager,
        private readonly Security $security,
        private readonly ModuleContributionCatalog $contributions,
    ) {
    }

    #[Route('/aacp/content/{id}/revisions', name: 'aacp_content_revisions', methods: ['GET'], requirements: ['id' => '\d+'])]
    #[IsGranted('content.revision.manage')]
    public function index(int $id): Response
    {
        $node = $this->requireNode($id);
        $editRoute = $this->contributions->studioEditRoutes()[$node->getType()] ?? null;

        return new Response($this->twig->render('aacp/content/revisions.html.twig', [
            'node' => $node,
            'revisions' => $this->revisions->list($node),
            'editRoute' => $editRoute,
            'csrf_token' => $this->csrfTokenManager->getToken('aacp_content_revisions')->getValue(),
        ]));
    }

    #[Route('/aacp/content/{id}/revisions/{rev}/restore', name: 'aacp_content_revisions_restore', methods: ['POST'], requirements: ['id' => '\d+', 'rev' => '\d+'])]
    #[IsGranted('content.revision.manage')]
    public function restore(int $id, int $rev, Request $request): RedirectResponse
    {
        if (!$this->csrfTokenManager->isTokenValid(new CsrfToken('aacp_content_revisions', (string) $request->request->get('_token')))) {
            throw new BadRequestHttpException($this->translator->trans('aacp.revisions.invalid_csrf'));
        }

        $node = $this->requireNode($id);
        $revision = $this->revisionRepository->find($rev);
        if (!$revision instanceof NodeRevision || $revision->getNode()->getId() !== $node->getId()) {
            throw new NotFoundHttpException($this->translator->trans('aacp.revisions.not_found'));
        }

        $by = $this->security->getUser();
        $this->revisions->restore($revision, $by instanceof User ? $by : null);
        $this->entityManager->flush();

        return new RedirectResponse('/aacp/content/'.$id.'/revisions?restored=1');
    }

    private function requireNode(int $id): Node
    {
        $node = $this->nodes->find($id);
        if (!$node instanceof Node) {
            throw new NotFoundHttpException($this->translator->trans('aacp.revisions.node_not_found'));
        }

        return $node;
    }
}
