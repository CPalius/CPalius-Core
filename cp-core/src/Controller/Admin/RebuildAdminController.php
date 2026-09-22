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
 * Studio Tools — content-scoped recounts. Core jobs stay on AACP.
 */
#[Route('/admin/tools/rebuild', name: 'admin_rebuild_')]
#[IsGranted('admin.rebuild.run')]
final class RebuildAdminController extends AbstractController
{
    public const CSRF_ID = 'studio_rebuild';

    public function __construct(
        private readonly RebuilderRegistry $registry,
        private readonly RebuildBatchRunner $runner,
        private readonly CsrfTokenManagerInterface $csrfTokenManager,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route('', name: 'index', methods: ['GET'])]
    #[CpAdminMenu(
        label: 'studio.rebuild.menu',
        icon: 'heroicons:arrow-path',
        panel: 'studio',
        priority: 90,
        capability: 'admin.rebuild.run',
        group: 'studio.group.tools',
    )]
    public function index(): Response
    {
        return $this->render('admin/rebuild/index.html.twig', [
            'jobs' => $this->jobPayloads($this->registry->forStudio(), 'admin_rebuild_run'),
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

        if (!$this->registry->has($id) || !$this->registry->get($id)->isStudioVisible()) {
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
    private function jobPayloads(array $rebuilders, string $runRoute): array
    {
        $jobs = [];
        foreach ($rebuilders as $rebuilder) {
            $jobs[] = [
                'id' => $rebuilder->getId(),
                'name' => $rebuilder->getName(),
                'description' => $rebuilder->getDescription(),
                'batchSize' => $rebuilder->getBatchSize(),
                'total' => $rebuilder->getTotal(),
                'runUrl' => $this->generateUrl($runRoute, ['id' => $rebuilder->getId()]),
            ];
        }

        return $jobs;
    }
}
