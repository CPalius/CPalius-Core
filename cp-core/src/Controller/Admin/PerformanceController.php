<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Core\Annotation\CpAdminMenu;
use App\Core\OriginCache\OriginCachePurger;
use App\Core\Performance\PerformanceBackendRegistry;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

/**
 * AACP Performance console for Redis/Memcached/Varnish/PageSpeed.
 * JSON actions translate message keys here; the GET index leaves keys for Twig |trans.
 */
final class PerformanceController
{
    private const CSRF_TOKEN_ID = 'aacp_performance';

    public function __construct(
        private readonly Environment $twig,
        private readonly CsrfTokenManagerInterface $csrfTokenManager,
        private readonly PerformanceBackendRegistry $registry,
        private readonly OriginCachePurger $originCachePurger,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route('/aacp/performance', name: 'aacp_performance', methods: ['GET'])]
    #[CpAdminMenu(label: 'aacp.menu.performance_rmvp', icon: 'heroicons:cpu-chip', panel: 'aacp', priority: 82, capability: 'system.performance.manage', parent: 'aacp_hub_maintenance')]
    #[IsGranted('system.performance.manage')]
    public function index(): Response
    {
        $backends = ['cpalius', 'redis', 'memcached', 'varnish', 'pagespeed'];
        $statuses = $this->registry->getAllStatuses();

        $sections = [];
        foreach ($backends as $backendId) {
            $sections[$backendId] = [
                'config' => $this->registry->getConfig($backendId),
                'status' => $statuses[$backendId] ?? null,
            ];
        }

        $html = $this->twig->render('aacp/performance/index.html.twig', [
            'sections' => $sections,
            'csrf_token' => $this->csrfTokenManager->getToken(self::CSRF_TOKEN_ID)->getValue(),
        ]);

        $response = new Response($html);
        $response->headers->set('Cache-Control', 'private, no-store, no-cache, must-revalidate');

        return $response;
    }

    #[Route('/aacp/performance/{backend}/test', name: 'aacp_performance_test', methods: ['POST'], requirements: ['backend' => 'cpalius|redis|memcached|varnish|pagespeed'])]
    #[IsGranted('system.performance.manage')]
    public function test(string $backend, Request $request): JsonResponse
    {
        if (!$this->isValidToken($request)) {
            return new JsonResponse(['success' => false, 'message' => $this->translator->trans('aacp.performance.invalid_csrf')], 400);
        }

        /** @var array<string, string> $submittedConfig */
        $submittedConfig = $request->request->all('config');

        $result = $this->registry->saveConfigAndTest($backend, $submittedConfig);

        return new JsonResponse($this->translateResult($result->toArray()));
    }

    #[Route('/aacp/performance/{backend}/enable', name: 'aacp_performance_enable', methods: ['POST'], requirements: ['backend' => 'cpalius|redis|memcached|varnish|pagespeed'])]
    #[IsGranted('system.performance.manage')]
    public function enable(string $backend, Request $request): JsonResponse
    {
        if (!$this->isValidToken($request)) {
            return new JsonResponse(['success' => false, 'message' => $this->translator->trans('aacp.performance.invalid_csrf')], 400);
        }

        try {
            $this->registry->enable($backend);
        } catch (\DomainException $e) {
            return new JsonResponse(['success' => false, 'message' => $this->translator->trans($e->getMessage())], 422);
        }

        return new JsonResponse(['success' => true]);
    }

    #[Route('/aacp/performance/{backend}/disable', name: 'aacp_performance_disable', methods: ['POST'], requirements: ['backend' => 'cpalius|redis|memcached|varnish|pagespeed'])]
    #[IsGranted('system.performance.manage')]
    public function disable(string $backend, Request $request): JsonResponse
    {
        if (!$this->isValidToken($request)) {
            return new JsonResponse(['success' => false, 'message' => $this->translator->trans('aacp.performance.invalid_csrf')], 400);
        }

        $this->registry->disable($backend);

        return new JsonResponse(['success' => true]);
    }

    #[Route('/aacp/performance/cpalius/purge', name: 'aacp_performance_cpalius_purge', methods: ['POST'])]
    #[IsGranted('system.performance.manage')]
    public function purgeOriginCache(Request $request): JsonResponse
    {
        if (!$this->isValidToken($request)) {
            return new JsonResponse(['success' => false, 'message' => $this->translator->trans('aacp.performance.invalid_csrf')], 400);
        }

        $deleted = $this->originCachePurger->purgeAll();

        return new JsonResponse([
            'success' => true,
            'message' => $this->translator->trans('aacp.performance.cpalius.purged', ['count' => $deleted]),
        ]);
    }

    /**
     * @param array{success: bool, status: string, messageKey: string, messageParams: array<string, mixed>, latencyMs: ?float, details: array<string, mixed>} $result
     *
     * @return array{success: bool, status: string, message: string, latencyMs: ?float, details: array<string, mixed>}
     */
    private function translateResult(array $result): array
    {
        return [
            'success' => $result['success'],
            'status' => $result['status'],
            'message' => $this->translator->trans($result['messageKey'], $result['messageParams']),
            'latencyMs' => $result['latencyMs'],
            'details' => $result['details'],
        ];
    }

    private function isValidToken(Request $request): bool
    {
        $submitted = (string) $request->request->get('_token');
        if ($submitted === '') {
            $submitted = (string) $request->headers->get('X-CSRF-TOKEN', '');
        }

        return $submitted !== '' && $this->csrfTokenManager->isTokenValid(new CsrfToken(self::CSRF_TOKEN_ID, $submitted));
    }
}
