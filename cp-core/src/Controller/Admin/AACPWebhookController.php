<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Core\Annotation\CpAdminMenu;
use App\Core\Webhook\Entity\WebhookSubscription;
use App\Core\Webhook\WebhookSigner;
use App\Core\Webhook\WebhookSubscriptionService;
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
 * Outbound webhook subscription admin (plain Twig + POST-redirect-GET, same
 * convention as AACPCronController). The signing secret is rendered once,
 * straight into the response — never redirected through a URL.
 */
final class AACPWebhookController
{
    public function __construct(
        private readonly Environment $twig,
        private readonly CsrfTokenManagerInterface $csrfTokenManager,
        private readonly WebhookSubscriptionService $subscriptions,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route('/aacp/webhooks', name: 'aacp_webhooks', methods: ['GET'])]
    #[CpAdminMenu(label: 'aacp.menu.webhooks', icon: 'heroicons:arrow-up-right', panel: 'aacp', priority: 24, capability: 'system.webhooks.manage', parent: 'aacp_tools')]
    #[IsGranted('system.webhooks.manage')]
    public function index(): Response
    {
        return new Response($this->renderIndex());
    }

    #[Route('/aacp/webhooks/new', name: 'aacp_webhooks_new', methods: ['GET'])]
    #[IsGranted('system.webhooks.manage')]
    public function new(): Response
    {
        return new Response($this->twig->render('aacp/webhooks/form.html.twig', [
            'subscription' => null,
            'csrf_token' => $this->token(),
            'signatureHeader' => WebhookSigner::HEADER,
        ]));
    }

    #[Route('/aacp/webhooks/{id}/edit', name: 'aacp_webhooks_edit', methods: ['GET'], requirements: ['id' => '\d+'])]
    #[IsGranted('system.webhooks.manage')]
    public function edit(int $id): Response
    {
        return new Response($this->twig->render('aacp/webhooks/form.html.twig', [
            'subscription' => $this->findOrFail($id),
            'csrf_token' => $this->token(),
            'signatureHeader' => WebhookSigner::HEADER,
        ]));
    }

    #[Route('/aacp/webhooks/save', name: 'aacp_webhooks_save', methods: ['POST'])]
    #[IsGranted('system.webhooks.manage')]
    public function save(Request $request): Response
    {
        $this->assertValidCsrfToken($request);

        $id = $request->request->getInt('id') ?: null;
        $label = trim((string) $request->request->get('label'));
        $url = trim((string) $request->request->get('url'));
        $events = $this->parseEvents((string) $request->request->get('events'));

        try {
            if ($id !== null) {
                $this->subscriptions->update($id, $label, $url, $events);

                return new RedirectResponse('/aacp/webhooks');
            }

            $result = $this->subscriptions->create($label, $url, $events);
        } catch (\InvalidArgumentException $e) {
            return new RedirectResponse($this->formRedirectUrl($id).'?error='.rawurlencode($e->getMessage()));
        }

        return new Response($this->renderIndex($result['secret'], $result['subscription']->getId()));
    }

    #[Route('/aacp/webhooks/{id}/rotate-secret', name: 'aacp_webhooks_rotate', methods: ['POST'], requirements: ['id' => '\d+'])]
    #[IsGranted('system.webhooks.manage')]
    public function rotateSecret(int $id, Request $request): Response
    {
        $this->assertValidCsrfToken($request);

        $secret = $this->subscriptions->rotateSecret($id);
        if ($secret === null) {
            throw new NotFoundHttpException($this->translator->trans('aacp.webhooks.not_found'));
        }

        return new Response($this->renderIndex($secret, $id));
    }

    #[Route('/aacp/webhooks/{id}/toggle', name: 'aacp_webhooks_toggle', methods: ['POST'], requirements: ['id' => '\d+'])]
    #[IsGranted('system.webhooks.manage')]
    public function toggle(int $id, Request $request): RedirectResponse
    {
        $this->assertValidCsrfToken($request);

        $subscription = $this->findOrFail($id);
        $this->subscriptions->setActive($id, !$subscription->isActive());

        return new RedirectResponse('/aacp/webhooks');
    }

    #[Route('/aacp/webhooks/{id}/delete', name: 'aacp_webhooks_delete', methods: ['POST'], requirements: ['id' => '\d+'])]
    #[IsGranted('system.webhooks.manage')]
    public function delete(int $id, Request $request): RedirectResponse
    {
        $this->assertValidCsrfToken($request);

        if (!$this->subscriptions->delete($id)) {
            throw new NotFoundHttpException($this->translator->trans('aacp.webhooks.not_found'));
        }

        return new RedirectResponse('/aacp/webhooks');
    }

    private function renderIndex(?string $revealedSecret = null, ?int $revealedForId = null): string
    {
        return $this->twig->render('aacp/webhooks/index.html.twig', [
            'subscriptions' => $this->subscriptions->list(),
            'csrf_token' => $this->token(),
            'revealedSecret' => $revealedSecret,
            'revealedForId' => $revealedForId,
            'quarantineAfter' => WebhookSubscription::QUARANTINE_AFTER,
        ]);
    }

    /**
     * @return list<string>
     */
    private function parseEvents(string $raw): array
    {
        $parts = preg_split('/[\s,]+/', trim($raw)) ?: [];

        return array_values(array_filter($parts, static fn (string $p): bool => $p !== ''));
    }

    private function findOrFail(int $id): WebhookSubscription
    {
        $subscription = $this->subscriptions->find($id);
        if (!$subscription instanceof WebhookSubscription) {
            throw new NotFoundHttpException($this->translator->trans('aacp.webhooks.not_found'));
        }

        return $subscription;
    }

    private function formRedirectUrl(?int $id): string
    {
        return $id !== null ? '/aacp/webhooks/'.$id.'/edit' : '/aacp/webhooks/new';
    }

    private function token(): string
    {
        return $this->csrfTokenManager->getToken('aacp_webhooks')->getValue();
    }

    private function assertValidCsrfToken(Request $request): void
    {
        $submittedToken = (string) $request->request->get('_token');
        if (!$this->csrfTokenManager->isTokenValid(new CsrfToken('aacp_webhooks', $submittedToken))) {
            throw new BadRequestHttpException($this->translator->trans('aacp.webhooks.invalid_csrf'));
        }
    }
}
