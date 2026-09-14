<?php

declare(strict_types=1);

namespace Modules\Forum\Controller;

use App\Core\Security\Flood\FloodService;
use Modules\Forum\Service\ForumBodyPresenter;
use Modules\Forum\Service\ForumLinkUnfurlService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

final class ForumUnfurlController extends AbstractController
{
    private const FLOOD_EVENT = 'forum_unfurl';
    private const FLOOD_LIMIT = 30;
    private const FLOOD_WINDOW = 600;

    public function __construct(
        private readonly ForumLinkUnfurlService $unfurlService,
        private readonly ForumBodyPresenter $bodyPresenter,
        private readonly FloodService $flood,
    ) {
    }

    /**
     * Server-side fetch of a caller URL: requires topic.create, CSRF, and a per-account flood cap.
     */
    #[Route('/forums/unfurl', name: 'forum_unfurl', methods: ['POST'], priority: 5)]
    #[IsGranted('forum.topic.create')]
    public function unfurl(Request $request): JsonResponse
    {
        if (!$this->isCsrfTokenValid('forum_unfurl', (string) $request->request->get('_token'))) {
            return new JsonResponse(['ok' => false], Response::HTTP_FORBIDDEN);
        }

        $identifier = (string) ($this->getUser()?->getUserIdentifier() ?? $request->getClientIp() ?? '');
        if ($identifier !== '') {
            if (!$this->flood->isAllowed(self::FLOOD_EVENT, $identifier, self::FLOOD_LIMIT, self::FLOOD_WINDOW)) {
                return new JsonResponse(['ok' => false], Response::HTTP_TOO_MANY_REQUESTS);
            }
            $this->flood->register(self::FLOOD_EVENT, $identifier, self::FLOOD_WINDOW);
        }

        $url = trim((string) $request->request->get('url'));
        if ($url === '' || mb_strlen($url) > 2048 || !$this->unfurlService->isPublicHttpUrl($url)) {
            return new JsonResponse(['ok' => false], Response::HTTP_BAD_REQUEST);
        }

        try {
            $preview = $this->unfurlService->preview($url, true);
        } catch (\Throwable) {
            return new JsonResponse(['ok' => false], Response::HTTP_SERVICE_UNAVAILABLE);
        }

        $payload = $this->unfurlService->toCardPayload($preview, $url);
        if (!$payload['ok']) {
            return new JsonResponse($payload);
        }

        $payload['html'] = $this->bodyPresenter->cardHtml($payload);

        return new JsonResponse($payload);
    }
}
