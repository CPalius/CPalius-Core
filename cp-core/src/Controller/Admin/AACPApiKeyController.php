<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Core\Annotation\CpAdminMenu;
use App\Core\Api\ApiKeyService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

/**
 * Phase 7B API key admin (plain Twig + AJAX mutations).
 * Session + system.api.manage gate creation; keys authenticate via X-CP-API-KEY separately.
 */
final class AACPApiKeyController
{
    /**
     * @param list<array{path: string, methods: list<string>, public: bool, capability?: ?string, serviceId: string, method: string}> $apiDefinitions
     */
    public function __construct(
        private readonly Environment $twig,
        private readonly CsrfTokenManagerInterface $csrfTokenManager,
        private readonly ApiKeyService $apiKeyService,
        private readonly TranslatorInterface $translator,
        private readonly array $apiDefinitions = [],
    ) {
    }

    #[Route('/aacp/api-keys', name: 'aacp_api_keys', methods: ['GET'])]
    #[CpAdminMenu(label: 'aacp.menu.api_keys', icon: 'heroicons:key', panel: 'aacp', priority: 75, capability: 'system.api.manage', parent: 'aacp_hub_automation')]
    #[IsGranted('system.api.manage')]
    public function index(): Response
    {
        $html = $this->twig->render('aacp/api_keys/index.html.twig', [
            'apiKeys' => $this->apiKeyService->findAll(),
            'knownCapabilities' => $this->knownCapabilities(),
            'csrf_token' => $this->csrfTokenManager->getToken('aacp_api_keys')->getValue(),
        ]);

        return new Response($html);
    }

    #[Route('/aacp/api-keys/create', name: 'aacp_api_keys_create', methods: ['POST'])]
    #[IsGranted('system.api.manage')]
    public function create(Request $request): JsonResponse
    {
        $this->assertValidCsrfToken($request);

        $label = trim((string) $request->request->get('label'));

        if ($label === '') {
            return new JsonResponse(['error' => $this->translator->trans('aacp.api_keys.label_required')], Response::HTTP_BAD_REQUEST);
        }

        $capabilities = $this->splitLines((string) $request->request->get('capabilities'));
        $ipAllowlist = $this->splitLines((string) $request->request->get('ip_allowlist'));
        $tenantId = trim((string) $request->request->get('tenant_id'));

        $expiresAt = null;
        $expiresRaw = trim((string) $request->request->get('expires_at'));
        if ($expiresRaw !== '') {
            try {
                $expiresAt = new \DateTimeImmutable($expiresRaw);
            } catch (\Exception) {
                return new JsonResponse(['error' => $this->translator->trans('aacp.api_keys.invalid_expiry')], Response::HTTP_BAD_REQUEST);
            }
        }

        $result = $this->apiKeyService->generate(
            $label,
            $capabilities,
            $tenantId !== '' ? $tenantId : null,
            $ipAllowlist,
            $expiresAt,
        );

        return new JsonResponse([
            'key' => $result['key'],
            'apiKey' => $result['apiKey']->toPublicArray(),
        ], Response::HTTP_CREATED);
    }

    #[Route('/aacp/api-keys/{id}/toggle', name: 'aacp_api_keys_toggle', methods: ['POST'])]
    #[IsGranted('system.api.manage')]
    public function toggle(string $id, Request $request): JsonResponse
    {
        $this->assertValidCsrfToken($request);

        $updated = $this->apiKeyService->toggleActive($id);

        if ($updated === null) {
            return new JsonResponse(['error' => $this->translator->trans('aacp.api_keys.not_found')], Response::HTTP_NOT_FOUND);
        }

        return new JsonResponse(['apiKey' => $updated->toPublicArray()]);
    }

    #[Route('/aacp/api-keys/{id}/delete', name: 'aacp_api_keys_delete', methods: ['POST'])]
    #[IsGranted('system.api.manage')]
    public function delete(string $id, Request $request): JsonResponse
    {
        $this->assertValidCsrfToken($request);

        if (!$this->apiKeyService->delete($id)) {
            return new JsonResponse(['error' => $this->translator->trans('aacp.api_keys.not_found')], Response::HTTP_NOT_FOUND);
        }

        return new JsonResponse(['success' => true]);
    }

    private function assertValidCsrfToken(Request $request): void
    {
        $submittedToken = (string) ($request->request->get('_token') ?? $request->headers->get('X-CSRF-Token'));
        if (!$this->csrfTokenManager->isTokenValid(new CsrfToken('aacp_api_keys', $submittedToken))) {
            throw new BadRequestHttpException($this->translator->trans('aacp.api_keys.invalid_csrf'));
        }
    }

    /**
     * @return list<string>
     */
    private function splitLines(string $raw): array
    {
        $parts = preg_split('/[\r\n,]+/', trim($raw)) ?: [];

        return array_values(array_filter(array_map('trim', $parts), static fn (string $p): bool => $p !== ''));
    }

    /**
     * Concrete (non-templated) capabilities declared by registered #[CpApi] endpoints,
     * offered in the admin form as a datalist. Templated ones like "{name}.view" are skipped.
     *
     * @return list<string>
     */
    private function knownCapabilities(): array
    {
        $capabilities = [];
        foreach ($this->apiDefinitions as $definition) {
            $capability = $definition['capability'] ?? null;
            if (\is_string($capability) && $capability !== '' && !str_contains($capability, '{')) {
                $capabilities[$capability] = true;
            }
        }

        $names = array_keys($capabilities);
        sort($names);

        return $names;
    }
}
