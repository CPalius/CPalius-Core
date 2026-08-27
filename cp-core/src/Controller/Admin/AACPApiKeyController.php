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
 * Faz 7B: AACP "API Yönetimi" ekranı — REST API anahtarlarının üretilmesi,
 * listelenmesi ve silinmesi/pasif edilmesi. AACPCronController ile aynı
 * iskelet: plain class + inject edilen Twig\Environment, index() normal
 * bir sayfa render eder, mutasyon action'ları (create/toggle/delete) AJAX
 * ile çağrılıp JsonResponse döner.
 *
 * Güvenlik: bu controller'ın kendisi form_login firewall'ının (IS_AUTHENTICATED_FULLY,
 * ^/aacp access_control kuralı) ARKASINDADIR — yani "kim API anahtarı
 * üretebilir" sorusu normal AACP oturum kimlik doğrulamasıyla + CPaliusVoter
 * (system.api.manage) ile cevaplanır. Üretilen anahtarların KENDİSİ ise
 * ApiGatewayController üzerinden TAMAMEN AYRI, stateless bir X-CP-API-KEY
 * header doğrulamasıyla korunur (bkz. ApiKeyService::isValid()).
 */
final class AACPApiKeyController
{
    public function __construct(
        private readonly Environment $twig,
        private readonly CsrfTokenManagerInterface $csrfTokenManager,
        private readonly ApiKeyService $apiKeyService,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route('/aacp/api-keys', name: 'aacp_api_keys', methods: ['GET'])]
    #[CpAdminMenu(label: 'aacp.menu.api_keys', icon: 'heroicons:key', panel: 'aacp', priority: 23, capability: 'system.api.manage', group: 'aacp.group.system')]
    #[IsGranted('system.api.manage')]
    public function index(): Response
    {
        $html = $this->twig->render('aacp/api_keys/index.html.twig', [
            'apiKeys' => $this->apiKeyService->findAll(),
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

        $result = $this->apiKeyService->generate($label);

        return new JsonResponse([
            'key' => $result['key'],
            'apiKey' => $result['apiKey']->toArray(),
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

        return new JsonResponse(['apiKey' => $updated->toArray()]);
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
}
