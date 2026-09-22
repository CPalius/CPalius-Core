<?php

declare(strict_types=1);

namespace Modules\DnsTools\Api;

use App\Core\Api\Attribute\CpApi;
use Modules\DnsTools\Catalog\PresentedTool;
use Modules\DnsTools\Security\DnsToolsRateLimiter;
use Modules\DnsTools\Service\ToolRegistry;
use Modules\DnsTools\Service\ToolRunner;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Keyed JSON for the same catalogue the browser uses. The CSRF front route stays private.
 */
final class DnsToolsApiEndpoints
{
    public function __construct(
        private readonly ToolRegistry $registry,
        private readonly ToolRunner $runner,
        private readonly DnsToolsRateLimiter $rateLimiter,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[CpApi(path: '/dns-tools', methods: ['GET'], public: true)]
    public function catalog(): JsonResponse
    {
        return new JsonResponse([
            'data' => array_map(
                static fn (PresentedTool $tool): array => [
                    'slug' => $tool->slug,
                    'title' => $tool->title,
                    'category' => $tool->category,
                    'input' => $tool->input,
                    'network_probe' => $tool->networkProbe,
                    'path' => '/api/dns-tools/'.$tool->slug,
                ],
                $this->registry->all(),
            ),
        ]);
    }

    #[CpApi(path: '/dns-tools/{slug}', methods: ['GET', 'POST'], public: false, capability: 'dnstools.query')]
    public function run(Request $request, string $slug): JsonResponse
    {
        $tool = $this->registry->get($slug);
        if (!$tool instanceof PresentedTool) {
            return new JsonResponse(['ok' => false, 'error' => $this->translator->trans('dnstools.error.unknown_tool')], Response::HTTP_NOT_FOUND);
        }

        if (!$this->rateLimiter->consume($request, $tool->networkProbe ? 'probe' : 'query')) {
            return new JsonResponse([
                'ok' => false,
                'error' => $this->translator->trans('dnstools.error.rate_limit'),
                'code' => 'RATE_LIMIT',
            ], Response::HTTP_TOO_MANY_REQUESTS);
        }

        try {
            $result = $this->runner->run($slug, $this->input($request), $request);

            return new JsonResponse(['ok' => true, 'data' => $result]);
        } catch (\InvalidArgumentException $e) {
            return new JsonResponse([
                'ok' => false,
                'error' => $this->localize($e->getMessage()),
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        } catch (\Throwable $e) {
            return new JsonResponse([
                'ok' => false,
                'error' => $this->localize($e->getMessage(), 'dnstools.error.failed'),
            ], Response::HTTP_BAD_GATEWAY);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function input(Request $request): array
    {
        $contentType = strtolower((string) $request->headers->get('Content-Type', ''));
        if (str_contains($contentType, 'json')) {
            $decoded = json_decode($request->getContent(), true);

            return \is_array($decoded) ? $decoded : [];
        }

        /** @var array<string, mixed> $merged */
        $merged = $request->request->all() + $request->query->all();
        unset($merged['_token']);

        return $merged;
    }

    private function localize(string $message, string $fallback = 'dnstools.error.failed'): string
    {
        if (str_starts_with($message, 'dnstools.')) {
            return $this->translator->trans($message);
        }

        return $this->translator->trans($fallback);
    }
}
