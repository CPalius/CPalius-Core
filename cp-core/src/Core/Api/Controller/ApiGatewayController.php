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
 * Faz 7B: Modüler REST API sisteminin ÇEKİRDEK giriş kapısı.
 *
 * Gelen TÜM "/api/*" isteklerini tek bir route ile yakalar (bkz. #[Route]
 * altındaki wildcard) ve #[CpApi] ile tescillenmiş metotlarla path+method
 * eşleşmesine göre eşleştirip çalıştırır. Cotonti'nin "tek dosya = tek
 * uç nokta" pratik felsefesiyle Symfony'nin routing derleme karmaşığını
 * (her modülün kendi routes.yaml'ını API için ayrıca tanımlaması) BİLİNÇLİ
 * olarak devre dışı bırakır: modül geliştiricisi tek bir #[CpApi] attribute'u
 * ekler, gerisini bu gateway halleder.
 *
 * Kimlik Doğrulama: $public === false olan (varsayılan) her uç nokta için
 * "X-CP-API-KEY" header'ı ApiKeyService::isValid() ile doğrulanır. Geçersiz/
 * eksik anahtar -> anında düz JSON '{"error": "Unauthorized"}' + HTTP 401,
 * hedef metot HİÇ ÇAĞRILMAZ (fail-closed).
 *
 * Core Never Dies Zırhı: eşleşen metodun ÇALIŞTIRILMASI try/catch(Throwable)
 * içine alınır. Bir API endpoint'i çökerse tüm gateway'i (dolayısıyla
 * diğer TÜM API uç noktalarını) 500'e düşürmez; sadece o isteğe düz JSON
 * '{"error": "Internal API Error"}' + HTTP 500 döner, hata module_quarantine.log'a
 * yazılır.
 */
final class ApiGatewayController
{
    /**
     * @param ContainerInterface $serviceLocator #[CpApi] taşıyan servisleri
     *   id'leriyle lazy çözen bir ServiceLocator (bkz. ApiRegistrationPass).
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
     * "/blog/posts/{id}" kalıbını gerçek "/blog/posts/42" yoluyla eşleştirir.
     * Eşleşirse yakalanan {param} değerlerini SIRALI bir liste olarak döner
     * (hedef metoda pozisyonel argüman olarak geçirilir), eşleşmezse null.
     *
     * @return list<string>|null
     */
    private function matchPath(string $pattern, string $requestPath): ?array
    {
        $regex = preg_replace('/\{[a-zA-Z_][a-zA-Z0-9_]*\}/', '([^/]+)', preg_quote($pattern, '#'));
        $regex = str_replace('\\', '', $regex ?? '');

        if (preg_match('#^'.$regex.'$#', $requestPath, $matches) !== 1) {
            return null;
        }

        return array_slice($matches, 1);
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
