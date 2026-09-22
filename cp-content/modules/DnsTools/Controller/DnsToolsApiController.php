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
        $skipLimit = $slug === 'spam-score' && $action === 'poll';
        if (!$skipLimit && !$this->rateLimiter->consume($request, $tool->networkProbe ? 'probe' : 'query')) {
            return new JsonResponse([
                'ok' => false,
                'error' => $this->translator->trans('dnstools.error.rate_limit'),
                'code' => 'RATE_LIMIT',
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

            return new JsonResponse([
                'ok' => true,
                'data' => $result,
                'community' => $this->forumCtas->for($slug, $tool->title, $target, $result),
            ]);
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
