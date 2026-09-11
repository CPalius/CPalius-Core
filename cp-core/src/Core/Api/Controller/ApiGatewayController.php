<?php

declare(strict_types=1);

namespace App\Core\Api\Controller;

use App\Core\Api\ApiAccessAuditor;
use App\Core\Api\ApiCapabilityPolicy;
use App\Core\Api\ApiIdempotencyStore;
use App\Core\Api\ApiKey;
use App\Core\Api\ApiKeyService;
use App\Core\Api\ApiRateLimiter;
use App\Core\Database\TenantScope;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Single /api/* gateway for #[CpApi] methods.
 * Public endpoints skip the key. Private endpoints are fail-closed: valid X-CP-API-KEY plus a resolved capability.
 * Handler failures return 500 JSON and go to module_quarantine.log; they do not take down the gateway.
 */
final class ApiGatewayController
{
    /**
     * @param ContainerInterface                                                                                                      $serviceLocator lazy locator for #[CpApi] services
     * @param list<array{path: string, methods: list<string>, public: bool, capability?: ?string, serviceId: string, method: string}> $apiEndpoints
     */
    public function __construct(
        private readonly ContainerInterface $serviceLocator,
        private readonly ApiKeyService $apiKeyService,
        private readonly LoggerInterface $logger,
        private readonly string $projectDir,
        private readonly array $apiEndpoints,
        private readonly ApiCapabilityPolicy $capabilityPolicy = new ApiCapabilityPolicy(),
        private readonly ?ApiRateLimiter $rateLimiter = null,
        private readonly ?ApiIdempotencyStore $idempotencyStore = null,
        private readonly ?ApiAccessAuditor $auditor = null,
        private readonly ?TenantScope $tenantScope = null,
    ) {
    }

    #[Route('/api/{path}', name: 'cp_api_gateway', requirements: ['path' => '.+'], methods: ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'])]
    public function __invoke(Request $request, string $path): JsonResponse
    {
        $started = hrtime(true);
        $requestPath = '/'.ltrim($path, '/');
        $method = $request->getMethod();
        $clientIp = (string) $request->getClientIp();

        $endpoint = $this->matchEndpoint($requestPath, $method);

        if ($endpoint === null) {
            return new JsonResponse(['error' => 'Not Found'], Response::HTTP_NOT_FOUND);
        }

        $apiKey = $this->apiKeyService->authenticate($request);

        if ($this->rateLimiter !== null && !$this->rateLimiter->consume($request, $apiKey, 'api')) {
            $this->audit($apiKey, $requestPath, Response::HTTP_TOO_MANY_REQUESTS, $started, $clientIp);

            return new JsonResponse(['error' => 'Too Many Requests'], Response::HTTP_TOO_MANY_REQUESTS);
        }

        if (!$endpoint['definition']['public']) {
            if ($apiKey === null) {
                $this->audit(null, $requestPath, Response::HTTP_UNAUTHORIZED, $started, $clientIp);

                return new JsonResponse(['error' => 'Unauthorized'], Response::HTTP_UNAUTHORIZED);
            }

            $named = $this->namedPathParameters($endpoint['definition']['path'], $endpoint['pathParameters']);
            $required = $this->capabilityPolicy->interpolate(
                $endpoint['definition']['capability'] ?? null,
                $named,
            );

            if ($required === null || !$apiKey->hasCapability($required)) {
                $this->audit($apiKey, $requestPath, Response::HTTP_FORBIDDEN, $started, $clientIp, ['reason' => 'capability']);

                return new JsonResponse(['error' => 'Forbidden'], Response::HTTP_FORBIDDEN);
            }

            $this->tenantScope?->applyOptional($apiKey->tenantId);
        }

        if ($apiKey !== null && $this->idempotencyStore !== null) {
            $replay = $this->idempotencyStore->replay($request, $apiKey);
            if ($replay instanceof JsonResponse) {
                $this->audit($apiKey, $requestPath, $replay->getStatusCode(), $started, $clientIp, ['idempotent' => 1]);

                return $replay;
            }
        }

        $serviceId = $endpoint['definition']['serviceId'];
        $methodName = $endpoint['definition']['method'];

        if (!$this->serviceLocator->has($serviceId)) {
            return new JsonResponse(['error' => 'Not Found'], Response::HTTP_NOT_FOUND);
        }

        try {
            $service = $this->serviceLocator->get($serviceId);
            $result = $service->$methodName($request, ...$endpoint['pathParameters']);

            $response = $result instanceof JsonResponse ? $result : new JsonResponse($result);
            if ($apiKey !== null) {
                $this->idempotencyStore?->remember($request, $apiKey, $response);
            }
            $this->audit($apiKey, $requestPath, $response->getStatusCode(), $started, $clientIp);

            return $response;
        } catch (\Throwable $e) {
            $this->quarantineApiFailure($serviceId, $methodName, $e);
            $this->audit($apiKey, $requestPath, Response::HTTP_INTERNAL_SERVER_ERROR, $started, $clientIp);

            return new JsonResponse(['error' => 'Internal API Error'], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * @return array{definition: array{path: string, methods: list<string>, public: bool, capability?: ?string, serviceId: string, method: string}, pathParameters: list<string>}|null
     */
    private function matchEndpoint(string $requestPath, string $method): ?array
    {
        foreach ($this->apiEndpoints as $definition) {
            if (!in_array($method, $definition['methods'], true)) {
                continue;
            }

            $pathParameters = $this->matchPath($definition['path'], $requestPath);

            if ($pathParameters !== null) {
                return ['definition' => $definition, 'pathParameters' => $pathParameters];
            }
        }

        return null;
    }

    /**
     * Match "/blog/posts/{id}" to a request path. Quote literals only (API-01/02); first match wins.
     *
     * @return list<string>|null captured {param} values in order, or null
     */
    private function matchPath(string $pattern, string $requestPath): ?array
    {
        $detailed = $this->matchPathDetailed($pattern, $requestPath);

        return $detailed['values'] ?? null;
    }

    /**
     * @return array{values: list<string>, names: list<string>}|null
     */
    private function matchPathDetailed(string $pattern, string $requestPath): ?array
    {
        $segments = preg_split(
            '/(\{[a-zA-Z_][a-zA-Z0-9_]*\})/',
            $pattern,
            -1,
            \PREG_SPLIT_DELIM_CAPTURE | \PREG_SPLIT_NO_EMPTY,
        );

        if ($segments === false) {
            return null;
        }

        $regex = '';
        $names = [];
        foreach ($segments as $segment) {
            if (preg_match('/^\{([a-zA-Z_][a-zA-Z0-9_]*)\}$/', $segment, $nameMatch) === 1) {
                $regex .= '([^/]+)';
                $names[] = $nameMatch[1];
            } else {
                $regex .= preg_quote($segment, '#');
            }
        }

        if (preg_match('#^'.$regex.'$#', $requestPath, $matches) !== 1) {
            return null;
        }

        /** @var list<string> $values */
        $values = array_values(array_slice($matches, 1));

        return ['values' => $values, 'names' => $names];
    }

    /**
     * @param list<string> $values
     *
     * @return array<string, string>
     */
    private function namedPathParameters(string $pattern, array $values): array
    {
        preg_match_all('/\{([a-zA-Z_][a-zA-Z0-9_]*)\}/', $pattern, $matches);
        $named = [];
        foreach ($matches[1] as $i => $name) {
            if (isset($values[$i])) {
                $named[$name] = $values[$i];
            }
        }

        return $named;
    }

    /**
     * @param array<string, mixed> $meta
     */
    private function audit(?ApiKey $apiKey, string $path, int $status, int $startedHrtime, string $clientIp, array $meta = []): void
    {
        if ($this->auditor === null) {
            return;
        }

        $durationMs = (int) ((hrtime(true) - $startedHrtime) / 1_000_000);
        $this->auditor->record('call', $apiKey, $path, $status, $durationMs, $clientIp, $meta);
    }

    private function quarantineApiFailure(string $serviceId, string $method, \Throwable $e): void
    {
        $this->logger->error('Error while executing API endpoint.', [
            'service' => $serviceId,
            'method' => $method,
            'exception' => $e->getMessage(),
        ]);

        $logFile = $this->projectDir.'/cp-core/var/log/module_quarantine.log';
        $dir = \dirname($logFile);
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }

        $line = sprintf(
            '[%s] %s::%s() API endpoint skipped at runtime because it failed. Reason: %s',
            date('Y-m-d H:i:s'),
            $serviceId,
            $method,
            $e->getMessage(),
        );

        @file_put_contents($logFile, $line.PHP_EOL, FILE_APPEND | LOCK_EX);
    }
}
