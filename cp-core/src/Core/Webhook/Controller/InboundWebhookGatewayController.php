<?php

declare(strict_types=1);

namespace App\Core\Webhook\Controller;

use App\Core\Api\ApiRateLimiter;
use App\Core\Module\ModuleContributionCatalog;
use App\Core\Queue\AsyncJobDispatcherInterface;
use App\Core\Queue\Entity\AsyncJob;
use App\Core\Settings\SettingsRegistry;
use App\Core\Webhook\WebhookSigner;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Incoming machine webhooks. Signature is verified in core before any module code runs.
 */
final class InboundWebhookGatewayController
{
    public const MAX_BODY = 65536;

    public function __construct(
        private readonly ModuleContributionCatalog $contributions,
        private readonly SettingsRegistry $settingsRegistry,
        private readonly WebhookSigner $signer,
        private readonly AsyncJobDispatcherInterface $asyncJobBus,
        private readonly ApiRateLimiter $rateLimiter,
        private readonly CacheItemPoolInterface $cache,
    ) {
    }

    #[Route('/hooks/{endpoint}', name: 'cp_inbound_webhook', methods: ['POST'], requirements: ['endpoint' => '[a-z0-9][a-z0-9_.-]{0,63}'])]
    public function __invoke(Request $request, string $endpoint): JsonResponse
    {
        if (!$this->rateLimiter->consume($request, null, 'hooks')) {
            return new JsonResponse(['error' => 'Too Many Requests'], Response::HTTP_TOO_MANY_REQUESTS);
        }

        $definition = $this->contributions->inboundWebhook($endpoint);
        if ($definition === null) {
            return new JsonResponse(['error' => 'Not Found'], Response::HTTP_NOT_FOUND);
        }

        $contentType = strtolower((string) $request->headers->get('Content-Type', ''));
        if (!str_starts_with($contentType, 'application/json')) {
            return new JsonResponse(['error' => 'Unsupported Media Type'], Response::HTTP_UNSUPPORTED_MEDIA_TYPE);
        }

        $raw = $request->getContent();
        $max = min(self::MAX_BODY, $definition['maxBody']);
        if ($raw === '' || \strlen($raw) > $max) {
            return new JsonResponse(['error' => 'Payload too large'], Response::HTTP_REQUEST_ENTITY_TOO_LARGE);
        }

        $secret = (string) $this->settingsRegistry->get($definition['secretSetting'], '');
        if ($secret === '') {
            return new JsonResponse(['error' => 'Unauthorized'], Response::HTTP_UNAUTHORIZED);
        }

        $headerName = $definition['header'];
        $header = (string) $request->headers->get($headerName, '');
        if (!$this->signer->verify($header, $raw, $secret, time())) {
            return new JsonResponse(['error' => 'Unauthorized'], Response::HTTP_UNAUTHORIZED);
        }

        $replayKey = 'cp_hook_replay_'.hash('sha256', $endpoint.'|'.$header.'|'.$raw);
        try {
            $item = $this->cache->getItem($replayKey);
            if ($item->isHit()) {
                return new JsonResponse(['accepted' => true], Response::HTTP_ACCEPTED);
            }
            $item->set(1);
            $item->expiresAfter(WebhookSigner::MAX_SKEW_SECONDS * 2);
            $this->cache->save($item);
        } catch (\Throwable) {
            return new JsonResponse(['error' => 'Too Many Requests'], Response::HTTP_TOO_MANY_REQUESTS);
        }

        try {
            $decoded = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return new JsonResponse(['error' => 'Invalid JSON'], Response::HTTP_BAD_REQUEST);
        }

        if (!\is_array($decoded)) {
            return new JsonResponse(['error' => 'Invalid JSON'], Response::HTTP_BAD_REQUEST);
        }

        $this->asyncJobBus->dispatch(AsyncJob::TYPE_INBOUND_WEBHOOK, [
            'endpoint_id' => $endpoint,
            'body' => $decoded,
        ]);

        return new JsonResponse(['accepted' => true], Response::HTTP_ACCEPTED);
    }
}
