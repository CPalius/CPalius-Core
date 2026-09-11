<?php

declare(strict_types=1);

namespace Modules\Forum\Controller;

use Modules\Forum\Service\ForumBodyPresenter;
use Modules\Forum\Service\ForumLinkUnfurlService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class ForumUnfurlController extends AbstractController
{
    public function __construct(
        private readonly ForumLinkUnfurlService $unfurlService,
        private readonly ForumBodyPresenter $bodyPresenter,
    ) {
    }

    #[Route('/forums/unfurl', name: 'forum_unfurl', methods: ['POST'], priority: 5)]
    public function unfurl(Request $request): JsonResponse
    {
        if (!$this->isCsrfTokenValid('forum_unfurl', (string) $request->request->get('_token'))) {
            return new JsonResponse(['ok' => false], Response::HTTP_FORBIDDEN);
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
