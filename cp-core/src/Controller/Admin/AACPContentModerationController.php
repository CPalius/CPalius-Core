<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Core\Content\ContentModerationManager;
use App\Core\Module\ModuleContributionCatalog;
use App\Core\Workflow\Exception\WorkflowException;
use App\Core\Workflow\WorkflowRegistry;
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
 * Module-agnostic moderation panel for any node whose type has moderation on.
 */
final class AACPContentModerationController
{
    public function __construct(
        private readonly Environment $twig,
        private readonly CsrfTokenManagerInterface $csrfTokenManager,
        private readonly TranslatorInterface $translator,
        private readonly NodeRepository $nodes,
        private readonly ContentModerationManager $moderation,
        private readonly WorkflowRegistry $workflows,
        private readonly EntityManagerInterface $entityManager,
        private readonly Security $security,
        private readonly ModuleContributionCatalog $contributions,
    ) {
    }

    #[Route('/aacp/content/{id}/moderation', name: 'aacp_content_moderation', methods: ['GET'], requirements: ['id' => '\d+'])]
    #[IsGranted('content.moderate')]
    public function panel(int $id): Response
    {
        $node = $this->requireNode($id);
        if (!$this->moderation->isEnabled($node->getType())) {
            return new Response($this->twig->render('aacp/content/moderation_disabled.html.twig', ['node' => $node]));
        }

        $workflow = $this->workflows->get($this->moderation->workflowName($node->getType()));

        return new Response($this->twig->render('aacp/content/moderation.html.twig', [
            'node' => $node,
            'state' => $this->moderation->currentState($node),
            'workflow' => $workflow,
            'transitions' => $this->moderation->availableTransitions($node),
            'editRoute' => $this->contributions->studioEditRoutes()[$node->getType()] ?? null,
            'csrf_token' => $this->csrfTokenManager->getToken('aacp_content_moderation')->getValue(),
        ]));
    }

    #[Route('/aacp/content/{id}/moderation/{transition}', name: 'aacp_content_moderation_apply', methods: ['POST'], requirements: ['id' => '\d+', 'transition' => '[a-z][a-z0-9_]{0,49}'])]
    #[IsGranted('content.moderate')]
    public function apply(int $id, string $transition, Request $request): RedirectResponse
    {
        if (!$this->csrfTokenManager->isTokenValid(new CsrfToken('aacp_content_moderation', (string) $request->request->get('_token')))) {
            throw new BadRequestHttpException($this->translator->trans('aacp.moderation.invalid_csrf'));
        }

        $node = $this->requireNode($id);
        $comment = trim((string) $request->request->get('comment')) ?: null;
        $by = $this->security->getUser();

        try {
            $this->moderation->apply($node, $transition, $comment, $by instanceof User ? $by : null);
        } catch (WorkflowException $e) {
            return new RedirectResponse('/aacp/content/'.$id.'/moderation?error='.rawurlencode($e->getMessage()));
        }

        $this->entityManager->flush();

        return new RedirectResponse('/aacp/content/'.$id.'/moderation?applied=1');
    }

    private function requireNode(int $id): Node
    {
        $node = $this->nodes->find($id);
        if (!$node instanceof Node) {
            throw new NotFoundHttpException($this->translator->trans('aacp.moderation.node_not_found'));
        }

        return $node;
    }
}
