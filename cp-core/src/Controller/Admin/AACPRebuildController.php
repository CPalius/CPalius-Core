<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Core\Annotation\CpAdminMenu;
use App\Core\Rebuild\RebuildBatchRunner;
use App\Core\Rebuild\RebuilderInterface;
use App\Core\Rebuild\RebuilderRegistry;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * AACP Maintenance — every registered rebuilder, Core included.
 */
#[Route('/aacp/maintenance/rebuild', name: 'aacp_rebuild_')]
#[IsGranted('system.aacp.access')]
final class AACPRebuildController extends AbstractController
{
    public const CSRF_ID = 'aacp_rebuild';

    public function __construct(
        private readonly RebuilderRegistry $registry,
        private readonly RebuildBatchRunner $runner,
        private readonly CsrfTokenManagerInterface $csrfTokenManager,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route('', name: 'index', methods: ['GET'])]
    #[CpAdminMenu(
        label: 'aacp.rebuild.menu',
        icon: 'heroicons:arrow-path',
        panel: 'aacp',
        priority: 83,
        capability: 'system.aacp.access',
        parent: 'aacp_hub_maintenance',
    )]
    public function index(): Response
    {
        return $this->render('aacp/rebuild.html.twig', [
            'jobs' => $this->jobPayloads($this->registry->all()),
            'csrfToken' => $this->csrfTokenManager->getToken(self::CSRF_ID)->getValue(),
        ]);
    }

    #[Route('/{id}', name: 'run', methods: ['POST'], requirements: ['id' => '[a-z0-9][a-z0-9._-]*'])]
    public function run(string $id, Request $request): JsonResponse
    {
        $submitted = (string) $request->request->get('_token');
        if (!$this->csrfTokenManager->isTokenValid(new CsrfToken(self::CSRF_ID, $submitted))) {
            return new JsonResponse([
                'ok' => false,
                'error' => $this->translator->trans('studio.rebuild.csrf_invalid'),
            ], 400);
        }

        if (!$this->registry->has($id)) {
            return new JsonResponse([
                'ok' => false,
                'error' => $this->translator->trans('studio.rebuild.unknown'),
            ], 404);
        }

        try {
            return new JsonResponse($this->runner->run($this->registry->get($id), $request->request->getInt('offset', 0)));
        } catch (\Throwable) {
            return new JsonResponse([
                'ok' => false,
                'error' => $this->translator->trans('studio.rebuild.error'),
            ], 500);
        }
    }

    /**
     * @param array<string, RebuilderInterface> $rebuilders
     *
     * @return list<array{id: string, name: string, description: string, batchSize: int, total: int, runUrl: string}>
     */
    private function jobPayloads(array $rebuilders): array
    {
        $jobs = [];
        foreach ($rebuilders as $rebuilder) {
            $jobs[] = [
                'id' => $rebuilder->getId(),
                'name' => $rebuilder->getName(),
                'description' => $rebuilder->getDescription(),
                'batchSize' => $rebuilder->getBatchSize(),
                'total' => $rebuilder->getTotal(),
                'runUrl' => $this->generateUrl('aacp_rebuild_run', ['id' => $rebuilder->getId()]),
            ];
        }

        return $jobs;
    }
}
