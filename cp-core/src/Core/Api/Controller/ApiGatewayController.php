<?php

declare(strict_types=1);

namespace App\Core\Api\Controller;

use App\Core\Api\ApiKeyService;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Throwable;

/**
 * Single /api/* gateway for #[CpApi] methods. Non-public endpoints require X-CP-API-KEY (fail-closed).
 * Handler failures return 500 JSON and go to module_quarantine.log; they do not take down the gateway.
 */
final class ApiGatewayController
{
    /**
     * @param ContainerInterface $serviceLocator Lazy locator for #[CpApi] services.
     * @param list<array{path: string, methods: list<string>, public: bool, serviceId: string, method: string}> $apiEndpoints
     */
    public function __construct(
        private readonly ContainerInterface $serviceLocator,
        private readonly ApiKeyService $apiKeyService,
        private readonly LoggerInterface $logger,
        private readonly string $projectDir,
        private readonly array $apiEndpoints,
    ) {
    }

    #[Route('/api/{path}', name: 'cp_api_gateway', requirements: ['path' => '.+'], methods: ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'])]
    public function __invoke(Request $request, string $path): JsonResponse
    {
        $requestPath = '/'.ltrim($path, '/');
        $method = $request->getMethod();

        $endpoint = $this->matchEndpoint($requestPath, $method);

        if ($endpoint === null) {
            return new JsonResponse(['error' => 'Not Found'], Response::HTTP_NOT_FOUND);
        }

        if (!$endpoint['definition']['public'] && !$this->hasValidApiKey($request)) {
            return new JsonResponse(['error' => 'Unauthorized'], Response::HTTP_UNAUTHORIZED);
        }

        $serviceId = $endpoint['definition']['serviceId'];
        $methodName = $endpoint['definition']['method'];

        if (!$this->serviceLocator->has($serviceId)) {
            return new JsonResponse(['error' => 'Not Found'], Response::HTTP_NOT_FOUND);
        }

        try {
            $service = $this->serviceLocator->get($serviceId);
            $result = $service->$methodName($request, ...$endpoint['pathParameters']);

            return $result instanceof JsonResponse ? $result : new JsonResponse($result);
        } catch (Throwable $e) {
            $this->quarantineApiFailure($serviceId, $methodName, $e);

            return new JsonResponse(['error' => 'Internal API Error'], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * @return array{definition: array{path: string, methods: list<string>, public: bool, serviceId: string, method: string}, pathParameters: list<string>}|null
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
     * @return list<string>|null Captured {param} values in order, or null.
     */
    private function matchPath(string $pattern, string $requestPath): ?array
    {
        // Keep placeholders in the split result so literals and {params} stay in order.
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
        foreach ($segments as $segment) {
            $regex .= preg_match('/^\{[a-zA-Z_][a-zA-Z0-9_]*\}$/', $segment) === 1
                ? '([^/]+)'
                : preg_quote($segment, '#');
        }

        if (preg_match('#^'.$regex.'$#', $requestPath, $matches) !== 1) {
            return null;
        }

        return array_values(array_slice($matches, 1));
    }

    private function hasValidApiKey(Request $request): bool
    {
        $providedKey = $request->headers->get('X-CP-API-KEY', '');

        return $this->apiKeyService->isValid((string) $providedKey);
    }

    private function quarantineApiFailure(string $serviceId, string $method, Throwable $e): void
    {
        $this->logger->error('API endpoint çalıştırılırken hata oluştu.', [
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
            '[%s] %s::%s() API uç noktası çalışırken hata verdiği için çalışma anında atlandı. Sebep: %s',
            date('Y-m-d H:i:s'),
            $serviceId,
            $method,
            $e->getMessage(),
        );

        @file_put_contents($logFile, $line.PHP_EOL, FILE_APPEND | LOCK_EX);
    }
}
