<?php

declare(strict_types=1);

namespace Modules\DnsTools\Controller;

use Modules\DnsTools\Catalog\PresentedTool;
use Modules\DnsTools\Security\DnsToolsRateLimiter;
use Modules\DnsTools\Service\ForumCtas;
use Modules\DnsTools\Service\ToolRegistry;
use Modules\DnsTools\Service\ToolRunner;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

#[Route('/dns-tools/api')]
final class DnsToolsApiController extends AbstractController
{
    public function __construct(
        private readonly ToolRegistry $registry,
        private readonly ToolRunner $runner,
        private readonly ForumCtas $forumCtas,
        private readonly DnsToolsRateLimiter $rateLimiter,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route('/{slug}', name: 'dnstools_api', methods: ['POST'], requirements: ['slug' => '[a-z0-9\-]+'])]
    public function run(string $slug, Request $request): JsonResponse
    {
        $tool = $this->registry->get($slug);
        if (!$tool instanceof PresentedTool) {
            return $this->error($this->translator->trans('dnstools.error.unknown_tool'), Response::HTTP_NOT_FOUND);
        }

        if (!$this->isCsrfTokenValid('dnstools_query', (string) $request->request->get('_token'))) {
            return $this->error($this->translator->trans('dnstools.error.csrf'), Response::HTTP_BAD_REQUEST);
        }

        $action = trim((string) $request->request->get('action', ''));
        $skipLimit = $slug === 'spam-score' && \in_array($action, ['poll', 'check'], true);
        if (!$skipLimit && !$this->rateLimiter->consume($request, $tool->networkProbe ? 'probe' : 'query')) {
            return new JsonResponse([
                'ok' => false,
                'error' => $this->translator->trans('dnstools.error.rate_limit'),
                'code' => 'RATE_LIMIT',
            ], Response::HTTP_TOO_MANY_REQUESTS);
        }
        if ($slug === 'spam-score' && !$skipLimit && !$this->rateLimiter->consumeSpamQuota($request)) {
            return new JsonResponse([
                'ok' => false,
                'error' => $this->translator->trans('dnstools.error.spam_quota'),
                'code' => 'RATE_LIMIT',
                'quota' => $this->rateLimiter->spamQuota($request),
            ], Response::HTTP_TOO_MANY_REQUESTS);
        }

        try {
            /** @var array<string, mixed> $input */
            $input = $request->request->all();
            $result = $this->runner->run($slug, $input, $request);
            $target = trim((string) ($input['q'] ?? $input['domain'] ?? $input['left'] ?? ''));
            if ($slug === 'spam-score') {
                $target = trim((string) ($result['from'] ?? $result['address'] ?? ''));
            }

            $payload = [
                'ok' => true,
                'data' => $result,
                'community' => $this->forumCtas->for($slug, $tool->title, $target, $result),
            ];
            if ($slug === 'spam-score') {
                $payload['quota'] = $this->rateLimiter->spamQuota($request);
            }

            return new JsonResponse($payload);
        } catch (\InvalidArgumentException $e) {
            return $this->error($this->localize($e->getMessage()), Response::HTTP_UNPROCESSABLE_ENTITY);
        } catch (\Throwable $e) {
            return $this->error($this->localize($e->getMessage(), 'dnstools.error.failed'), Response::HTTP_BAD_GATEWAY);
        }
    }

    private function localize(string $message, string $fallback = 'dnstools.error.failed'): string
    {
        if (str_starts_with($message, 'dnstools.')) {
            return $this->translator->trans($message);
        }

        return $this->translator->trans($fallback);
    }

    private function error(string $message, int $status): JsonResponse
    {
        return new JsonResponse(['ok' => false, 'error' => $message], $status);
    }
}
