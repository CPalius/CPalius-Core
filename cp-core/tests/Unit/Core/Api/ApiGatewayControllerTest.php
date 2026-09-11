<?php

declare(strict_types=1);

namespace App\Tests\Unit\Core\Api;

use App\Core\Api\ApiKeyService;
use App\Core\Api\Controller\ApiGatewayController;
use App\Entity\Setting;
use App\Repository\SettingRepository;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * API-01 / API-02 regression test — gateway path matching.
 * matchPath() is tested via reflection; __invoke() covers end-to-end routing.
 */
#[CoversClass(ApiGatewayController::class)]
final class ApiGatewayControllerTest extends TestCase
{
    // API-01 — Parameterised paths match

    /**
     * @return iterable<string, array{string, string, list<string>}>
     */
    public static function matchingPathProvider(): iterable
    {
        yield 'tek parametre' => [
            '/blog/posts/{id}', '/blog/posts/42', ['42'],
        ];
        yield 'tek parametre, alfanumerik' => [
            '/blog/posts/{slug}', '/blog/posts/merhaba-dunya', ['merhaba-dunya'],
        ];
        yield 'iki parametre' => [
            '/shop/{cat}/item/{sku}', '/shop/kitap/item/A9-X', ['kitap', 'A9-X'],
        ];
        yield 'uc parametre' => [
            '/a/{x}/b/{y}/c/{z}', '/a/1/b/2/c/3', ['1', '2', '3'],
        ];
        yield 'parametre sonda ve basta' => [
            '/{locale}/sayfa/{slug}', '/tr/sayfa/hakkimizda', ['tr', 'hakkimizda'],
        ];
        yield 'parametresiz yol' => [
            '/blog/posts', '/blog/posts', [],
        ];
        yield 'alt cizgili parametre adi' => [
            '/x/{post_id}', '/x/7', ['7'],
        ];
        yield 'URL kodlanmis deger' => [
            '/q/{term}', '/q/ara%20bul', ['ara%20bul'],
        ];
    }

    #[DataProvider('matchingPathProvider')]
    public function testParameterisedPathsMatchAndCapture(string $pattern, string $requestPath, array $expected): void
    {
        self::assertSame($expected, $this->matchPath($pattern, $requestPath));
    }

    /**
     * API-01 narrow case: placeholder must become a capture group, not literal text.
     * Literal "{id}" path also matches — captured value proves the distinction.
     */
    public function testPlaceholderBecomesCaptureGroupNotLiteralText(): void
    {
        self::assertSame(
            ['42'],
            $this->matchPath('/blog/posts/{id}', '/blog/posts/42'),
            'API-01 regresyonu: parametreli yol gercek bir kimlikle eslesmedi.',
        );

        // Literal "{id}" also matches — as a captured value, not empty array.
        self::assertSame(
            ['{id}'],
            $this->matchPath('/blog/posts/{id}', '/blog/posts/{id}'),
            'Yer tutucu bir yakalama grubuna donusmeli, literal metne degil.',
        );
    }

    // API-02 — Regex metacharacters treated as literals

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function regexMetacharacterProvider(): iterable
    {
        // Each row: [pattern, path that must NOT match]
        yield 'nokta joker olmamali'      => ['/v1.0/ping', '/v1X0/ping'];
        yield 'nokta joker olmamali (2)'  => ['/api.json', '/apiXjson'];
        yield 'arti tekrar olmamali'      => ['/a+b/x', '/aaab/x'];
        yield 'yildiz tekrar olmamali'    => ['/a*b/x', '/aaab/x'];
        yield 'soru isareti opsiyonel degil' => ['/ab?c/x', '/ac/x'];
        yield 'parantez grup olmamali'    => ['/(a|b)/x', '/a/x'];
        yield 'koseli parantez sinif degil' => ['/[abc]/x', '/a/x'];
        yield 'suslu parantez nicelik degil' => ['/a{2}/x', '/aa/x'];
    }

    #[DataProvider('regexMetacharacterProvider')]
    public function testRegexMetacharactersAreTreatedAsLiterals(string $pattern, string $shouldNotMatch): void
    {
        self::assertNull(
            $this->matchPath($pattern, $shouldNotMatch),
            sprintf('API-02 regresyonu: "%s" deseni "%s" yolunu yakaladi.', $pattern, $shouldNotMatch),
        );
    }

    /** Correct escaping must not break legitimate literal matches. */
    public function testLiteralMetacharactersStillMatchThemselves(): void
    {
        self::assertSame([], $this->matchPath('/v1.0/ping', '/v1.0/ping'));
        self::assertSame([], $this->matchPath('/api.json', '/api.json'));
        self::assertSame(['5'], $this->matchPath('/v1.0/item/{id}', '/v1.0/item/5'));
    }

    // Non-matching paths

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function nonMatchingPathProvider(): iterable
    {
        yield 'parametre / iceremez'   => ['/blog/posts/{id}', '/blog/posts/1/2'];
        yield 'bos parametre'          => ['/blog/posts/{id}', '/blog/posts/'];
        yield 'fazladan segment'       => ['/blog/posts', '/blog/posts/extra'];
        yield 'eksik segment'          => ['/blog/posts/{id}', '/blog/posts'];
        yield 'onek yeterli degil'     => ['/blog/posts', '/blog/postsX'];
        yield 'farkli yol'             => ['/blog/posts', '/forum/topics'];
        yield 'bastan eslesme sarti'   => ['/posts', '/blog/posts'];
    }

    #[DataProvider('nonMatchingPathProvider')]
    public function testNonMatchingPathsReturnNull(string $pattern, string $requestPath): void
    {
        self::assertNull($this->matchPath($pattern, $requestPath));
    }

    // End-to-end routing

    /** Captured values must reach the target method as positional arguments. */
    public function testCapturedParametersReachTheTargetMethod(): void
    {
        $endpoint = new class {
            /** @var list<string> */
            public array $received = [];

            public function show(Request $request, string $id): JsonResponse
            {
                $this->received[] = $id;

                return new JsonResponse(['id' => $id]);
            }
        };

        $controller = $this->createController(
            [[
                'path' => '/blog/posts/{id}',
                'methods' => ['GET'],
                'public' => true,
                'serviceId' => 'endpoint',
                'method' => 'show',
            ]],
            ['endpoint' => $endpoint],
            apiKeyValid: false,
        );

        $response = $controller(Request::create('/api/blog/posts/99'), 'blog/posts/99');

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        self::assertSame(['99'], $endpoint->received);
        self::assertSame('{"id":"99"}', $response->getContent());
    }

    /** private endpoint returns 401 without valid API key — target never called. */
    public function testPrivateEndpointRejectsRequestWithoutValidApiKey(): void
    {
        $endpoint = new class {
            public bool $called = false;

            public function show(Request $request, string $id): JsonResponse
            {
                $this->called = true;

                return new JsonResponse(['id' => $id]);
            }
        };

        $controller = $this->createController(
            [[
                'path' => '/admin/posts/{id}',
                'methods' => ['GET'],
                'public' => false,
                'serviceId' => 'endpoint',
                'method' => 'show',
            ]],
            ['endpoint' => $endpoint],
            apiKeyValid: false,
        );

        $response = $controller(Request::create('/api/admin/posts/7'), 'admin/posts/7');

        self::assertSame(Response::HTTP_UNAUTHORIZED, $response->getStatusCode());
        self::assertFalse($endpoint->called, 'Yetkisiz istekte hedef metot CAGRILMAMALIYDI.');
    }

    /** API-02 scenario: public pattern must not shadow a private endpoint. */
    public function testPublicPatternDoesNotShadowPrivateEndpoint(): void
    {
        $publicEndpoint = new class {
            public bool $called = false;

            public function ping(Request $request): JsonResponse
            {
                $this->called = true;

                return new JsonResponse(['pong' => true]);
            }
        };

        $controller = $this->createController(
            [
                // Without escaping, "." is a wildcard and would match "/v1Xadmin/secret".
                [
                    'path' => '/v1.admin/secret',
                    'methods' => ['GET'],
                    'public' => true,
                    'serviceId' => 'pub',
                    'method' => 'ping',
                ],
                [
                    'path' => '/v1Xadmin/secret',
                    'methods' => ['GET'],
                    'public' => false,
                    'serviceId' => 'pub',
                    'method' => 'ping',
                ],
            ],
            ['pub' => $publicEndpoint],
            apiKeyValid: false,
        );

        $response = $controller(Request::create('/api/v1Xadmin/secret'), 'v1Xadmin/secret');

        self::assertSame(
            Response::HTTP_UNAUTHORIZED,
            $response->getStatusCode(),
            'Public desen private ucu golgeledi — API-02 regresyonu.',
        );
        self::assertFalse($publicEndpoint->called);
    }

    public function testUnknownPathReturnsNotFound(): void
    {
        $controller = $this->createController([], [], apiKeyValid: true);

        $response = $controller(Request::create('/api/yok'), 'yok');

        self::assertSame(Response::HTTP_NOT_FOUND, $response->getStatusCode());
    }

    /** "Core Never Dies": throwing endpoint returns generic error without stack trace. */
    public function testThrowingEndpointReturnsGenericErrorWithoutLeakingDetails(): void
    {
        $endpoint = new class {
            public function boom(Request $request): JsonResponse
            {
                throw new \RuntimeException('gizli veritabani detayi');
            }
        };

        $controller = $this->createController(
            [[
                'path' => '/boom',
                'methods' => ['GET'],
                'public' => true,
                'serviceId' => 'endpoint',
                'method' => 'boom',
            ]],
            ['endpoint' => $endpoint],
            apiKeyValid: true,
        );

        $response = $controller(Request::create('/api/boom'), 'boom');

        self::assertSame(Response::HTTP_INTERNAL_SERVER_ERROR, $response->getStatusCode());
        self::assertStringNotContainsString('gizli veritabani detayi', (string) $response->getContent());
    }

    // Helpers

    /**
     * Invokes private matchPath() via reflection — see class docblock.
     *
     * @return list<string>|null
     */
    private function matchPath(string $pattern, string $requestPath): ?array
    {
        $controller = $this->createController([], [], apiKeyValid: false);

        $method = new \ReflectionMethod(ApiGatewayController::class, 'matchPath');

        /** @var list<string>|null $result */
        $result = $method->invoke($controller, $pattern, $requestPath);

        return $result;
    }

    /**
     * @param list<array{path: string, methods: list<string>, public: bool, serviceId: string, method: string}> $endpoints
     * @param array<string, object>                                                                             $services
     */
    private function createController(array $endpoints, array $services, bool $apiKeyValid): ApiGatewayController
    {
        $locator = new class($services) implements ContainerInterface {
            /** @param array<string, object> $services */
            public function __construct(private readonly array $services)
            {
            }

            public function get(string $id): object
            {
                return $this->services[$id];
            }

            public function has(string $id): bool
            {
                return isset($this->services[$id]);
            }
        };

        return new ApiGatewayController(
            $locator,
            $this->createApiKeyService($apiKeyValid),
            new NullLogger(),
            sys_get_temp_dir(),
            $endpoints,
        );
    }

    /**
     * Real ApiKeyService with mocked SettingRepository — final class cannot be doubled.
     * $valid true: active key in store; false: empty store, no key validates.
     */
    private function createApiKeyService(bool $valid): ApiKeyService
    {
        $repository = $this->createMock(SettingRepository::class);

        if (!$valid) {
            $repository->method('findOneBy')->willReturn(null);

            return new ApiKeyService($repository, $this->createMock(EntityManagerInterface::class));
        }

        // Populated but non-matching store — real match behaviour is ApiKeyService's own test.
        $setting = new Setting('core.api_keys', 'core');
        $setting->setSettingValue(json_encode([[
            'id' => 'test-id',
            'label' => 'test',
            'hash' => hash('sha256', 'cpk_test'),
            'lastFourChars' => 'test',
            'createdAt' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
            'active' => true,
        ]], \JSON_THROW_ON_ERROR));

        $repository->method('findOneBy')->willReturn($setting);

        return new ApiKeyService($repository, $this->createMock(EntityManagerInterface::class));
    }
}
