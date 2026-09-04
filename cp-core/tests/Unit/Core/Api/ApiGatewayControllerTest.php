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
 * API-01 / API-02 REGRESYON TESTİ — ağ geçidi yol eşleştirmesi.
 *
 * ══ Bu testin var olma sebebi ═══════════════════════════════════════════
 *
 * matchPath() üç satırlık bir metottu ve iki ayrı hata içeriyordu:
 *
 *   API-01: preg_quote() ÖNCE çağrılıyordu; süslü parantezleri kaçırdığı
 *           için ("{id}" -> "\{id\}") sonraki preg_replace() deseni artık
 *           eşleşmiyor ve yer tutucu bir yakalama grubuna DÖNÜŞMÜYORDU.
 *           Sonuç: #[CpApi] ile tanımlanan her parametreli uç nokta
 *           kalıcı olarak 404 dönüyordu.
 *
 *   API-02: str_replace('\\', '', $regex) tüm regex kaçışlarını
 *           siliyordu; nokta ve artı gibi meta karakterler joker'e
 *           dönüşüyordu. Bu bir YETKİLENDİRME riskidir: matchEndpoint()
 *           ilk eşleşende durduğu için, public: true bir desen
 *           public: false bir uç noktayı gölgeleyebilirdi.
 *
 * ══ Neden reflection ════════════════════════════════════════════════════
 *
 * matchPath() private'tır ve öyle KALMALIDIR — dışarıya açmak, testin
 * kolaylığı uğruna genel API'yi genişletmek olurdu. Buradaki iki hata da
 * tam olarak o metodun regex üretiminde yaşadığı için, testin doğrudan
 * oraya bakması hem en kesin hem de en okunur kanıttır. Ayrıca aşağıda
 * __invoke() üzerinden uçtan uca bir doğrulama da vardır: birim testi
 * "regex doğru mu", entegrasyon tarzı test "gerçekten doğru metoda
 * yönlendiriliyor ve yetki kontrolü çalışıyor mu" sorusunu yanıtlar.
 */
#[CoversClass(ApiGatewayController::class)]
final class ApiGatewayControllerTest extends TestCase
{
    // ═════════════════════════════════════════════════════════════════════
    // API-01 — Parametreli yollar artık eşleşiyor
    // ═════════════════════════════════════════════════════════════════════

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
     * Bu, API-01'in en dar hâlde ifadesidir: düzeltmeden ÖNCE
     * "/blog/posts/{id}" deseni SADECE literal "/blog/posts/{id}" yoluyla
     * eşleşiyor, gerçek bir kimlikle ("/blog/posts/42") eşleşmiyordu.
     * Yani yer tutucu bir yakalama grubuna hiç dönüşmüyordu.
     *
     * NOT: düzeltmeden SONRA da "/blog/posts/{id}" yolu eşleşir — ama
     * artık bunun sebebi "literal metin aynı" değil, "([^/]+) grubu
     * '{id}' dizesini yakaladı"dır. Bu doğru davranıştır: bir yol
     * parametresi süslü parantez içeren bir değer de taşıyabilir.
     * Ayrımı, yakalanan değeri kontrol ederek kanıtlıyoruz.
     */
    public function testPlaceholderBecomesCaptureGroupNotLiteralText(): void
    {
        self::assertSame(
            ['42'],
            $this->matchPath('/blog/posts/{id}', '/blog/posts/42'),
            'API-01 regresyonu: parametreli yol gercek bir kimlikle eslesmedi.',
        );

        // Literal "{id}" de eşleşir, ama YAKALANMIŞ bir değer olarak —
        // düzeltmeden önce hiçbir şey yakalanmıyordu (bos dizi dönerdi).
        self::assertSame(
            ['{id}'],
            $this->matchPath('/blog/posts/{id}', '/blog/posts/{id}'),
            'Yer tutucu bir yakalama grubuna donusmeli, literal metne degil.',
        );
    }

    // ═════════════════════════════════════════════════════════════════════
    // API-02 — Regex meta karakterleri artık literal
    // ═════════════════════════════════════════════════════════════════════

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function regexMetacharacterProvider(): iterable
    {
        // Her satır: [desen, eslesmemesi GEREKEN yol]
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

    /**
     * Kaçışın doğru olması, meşru eşleşmeyi BOZMAMALI: aynı desen kendi
     * literal karşılığıyla hâlâ eşleşmelidir.
     */
    public function testLiteralMetacharactersStillMatchThemselves(): void
    {
        self::assertSame([], $this->matchPath('/v1.0/ping', '/v1.0/ping'));
        self::assertSame([], $this->matchPath('/api.json', '/api.json'));
        self::assertSame(['5'], $this->matchPath('/v1.0/item/{id}', '/v1.0/item/5'));
    }

    // ═════════════════════════════════════════════════════════════════════
    // Eşleşmemesi gereken diğer durumlar
    // ═════════════════════════════════════════════════════════════════════

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

    // ═════════════════════════════════════════════════════════════════════
    // Uçtan uca: gerçekten doğru metoda yönlendiriliyor mu?
    // ═════════════════════════════════════════════════════════════════════

    /**
     * Regex doğru olsa bile, yakalanan değerler hedef metoda pozisyonel
     * argüman olarak GEÇMEZSE düzeltme yarım kalır. Bu test tüm zinciri
     * gerçek bir Request ile koşar.
     */
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

    /**
     * public: false olan bir uç nokta, geçersiz API anahtarıyla 401
     * dönmeli ve hedef metot HİÇ çağrılmamalıdır (fail-closed).
     */
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

    /**
     * API-02'nin asıl korktuğu senaryo: public bir desenin, private bir
     * uç noktanın yolunu gölgelemesi. Doğru kaçışla birlikte public desen
     * yalnızca kendi literal yolunu yakalar ve private uca giden istek
     * gerçekten 401 alır.
     */
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
                // Kaçışsız bir uygulamada "." joker olur ve
                // "/v1Xadmin/secret" gibi yolları da yakalardı.
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

    /**
     * "Core Never Dies": çöken bir uç nokta tüm ağ geçidini değil,
     * yalnızca o isteği etkiler ve gövdede yığın izi SIZDIRMAZ.
     */
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

    // ═════════════════════════════════════════════════════════════════════
    // Yardımcılar
    // ═════════════════════════════════════════════════════════════════════

    /**
     * matchPath() private olduğu için reflection ile çağrılır.
     * Bkz. sınıf docblock'undaki gerekçe.
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
     * ApiKeyService "final"dır ve doubling edilemez — bu bilinçli bir
     * tasarım kararıdır (güvenlik servisleri alt sınıflanarak
     * zayıflatılamamalı). Bu yüzden GERÇEK servis, sahte bir
     * SettingRepository ile kurulur.
     *
     * Bu, mock'lamaktan daha iyi bir testtir: doğrulanan şey artık
     * "bir mock false döndürdüğünde ne olur" değil, gerçek hash_equals
     * karşılaştırmasının gerçek depolama biçimiyle nasıl davrandığıdır.
     *
     * $valid true ise, depoda AKTİF bir anahtar varmış gibi davranılır ve
     * gateway'e o anahtarın kendisi gönderilmiş sayılır; false ise depo
     * boştur ve hiçbir anahtar doğrulanamaz.
     */
    private function createApiKeyService(bool $valid): ApiKeyService
    {
        $repository = $this->createMock(SettingRepository::class);

        if (!$valid) {
            $repository->method('findOneBy')->willReturn(null);

            return new ApiKeyService($repository, $this->createMock(EntityManagerInterface::class));
        }

        // isValid() boş dizeyi her koşulda reddeder; bu yüzden "geçerli"
        // senaryoda gateway'in gönderdiği boş header da reddedilirdi.
        // Testlerimizde "geçerli" senaryo yalnızca public uçlar ve hata
        // yolları için kullanıldığından, burada anahtar listesi dolu ama
        // eşleşmeyen bir depo yeterlidir; gerçek eşleşme davranışı
        // ApiKeyService'in kendi testinin konusudur.
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
