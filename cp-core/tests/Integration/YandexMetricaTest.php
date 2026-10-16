<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Core\Security\Http\SecurityHeaderPolicy;
use App\Tests\Support\IntegrationTestCase;
use Modules\VisitorStats\Service\YandexCspSources;
use Modules\VisitorStats\Service\YandexMetricaApi;
use Modules\VisitorStats\Service\YandexMetricaSettings;
use Modules\VisitorStats\Service\YandexOauth;
use Modules\VisitorStats\Service\YandexTracker;
use PHPUnit\Framework\Attributes\CoversNothing;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpFoundation\Request;

/** The Yandex Metrica port: OAuth exchange, API parsing, the counter snippet and its CSP hosts. */
#[CoversNothing]
final class YandexMetricaTest extends IntegrationTestCase
{
    public function testConfirmationCodeIsExchangedForAToken(): void
    {
        $seen = [];
        $http = new MockHttpClient(function (string $method, string $url, array $options) use (&$seen): MockResponse {
            $seen = [$method, $url, $options['body'] ?? ''];

            return new MockResponse('{"access_token":"tok-123","token_type":"bearer"}');
        });

        $result = (new YandexOauth($http))->exchange('1234567', 'app-id', 'app-secret');

        self::assertSame('tok-123', $result['token']);
        self::assertSame(['POST', 'https://oauth.yandex.com/token'], \array_slice($seen, 0, 2));
        self::assertStringContainsString('grant_type=authorization_code', $seen[2]);
        self::assertStringContainsString('code=1234567', $seen[2]);
        self::assertStringContainsString('client_secret=app-secret', $seen[2]);
    }

    public function testOauthErrorsUseThePluginsWording(): void
    {
        $bad = new MockHttpClient(new MockResponse('{"error":"invalid_grant"}', ['http_code' => 400]));
        self::assertSame('Invalid or expired authorization code.', (new YandexOauth($bad))->exchange('x', 'a', 'b')['error']);

        $down = new MockHttpClient(new MockResponse('', ['http_code' => 500]));
        self::assertStringStartsWith('The server encountered', (string) (new YandexOauth($down))->exchange('x', 'a', 'b')['error']);

        self::assertSame(
            'https://oauth.yandex.com/authorize?response_type=code&client_id=abc&display=popup',
            YandexOauth::authorizeUrl('abc'),
        );
    }

    public function testStatisticsAreParsedLikeThePlugin(): void
    {
        $body = json_encode([
            'time_intervals' => [['2026-10-01', '2026-10-01'], ['2026-10-02', '2026-10-02']],
            'data' => [['metrics' => [[10, 20], [4, 6], [3, 5], [50.0, 70.0], [1.5, 2.5], [60, 120]]]],
            'totals' => [[10, 20], [4, 6], [3, 5], [50.0, 70.0], [1.5, 2.5], [60, 120]],
        ], \JSON_THROW_ON_ERROR);
        $seenAuth = '';
        $http = new MockHttpClient(function (string $m, string $u, array $o) use ($body, &$seenAuth): MockResponse {
            $seenAuth = implode(',', $o['headers'] ?? []);

            return new MockResponse($body);
        });

        $stats = (new YandexMetricaApi($http, new ArrayAdapter()))->withToken('tok')->statistics(42, '2026-10-01', '2026-10-02');

        self::assertStringContainsString('Authorization: OAuth tok', $seenAuth);
        self::assertSame(30, $stats['total']['pageviews']);
        self::assertSame(10, $stats['total']['visits']);
        self::assertSame(60.0, $stats['total']['new_visitors']);
        self::assertEquals(['pageviews' => 10, 'visits' => 4, 'visitors' => 3, 'new_visitors' => 50.0, 'page_depth' => 1.5, 'duration' => 60], $stats['daily']['2026-10-01']);
    }

    public function testCounterValidityAcceptsOkOrActive(): void
    {
        $api = static fn (string $json): YandexMetricaApi => (new YandexMetricaApi(new MockHttpClient(new MockResponse($json)), new ArrayAdapter()))->withToken('t');

        self::assertTrue($api('{"counter":{"code_status":"CS_OK"}}')->isValidCounter(1));
        self::assertTrue($api('{"counter":{"code_status":"CS_ERR_UNKNOWN","status":"Active"}}')->isValidCounter(1));
        self::assertFalse($api('{"counter":{"code_status":"CS_ERR_UNKNOWN"}}')->isValidCounter(1));
        self::assertFalse($api('not json')->isValidCounter(1));
    }

    public function testStatsCardAndSettingsScreenRender(): void
    {
        $this->container();
        $this->pushRequest();
        $this->authenticateAs('admin');
        /** @var \Twig\Environment $twig */
        $twig = $this->container()->get('twig');

        $card = $twig->render('@VisitorStatsModule/admin/_yandex.html.twig', ['yandex' => [
            'period' => 'weekly', 'valid' => true,
            'total' => ['visits' => 10, 'visitors' => 7, 'pageviews' => 30, 'new_visitors' => 55.5, 'page_depth' => 2.25, 'duration' => 3725],
            'popular' => [['url' => 'https://x.test/a', 'pageviews' => 9]],
            'referrers' => [], 'searches' => [['name' => 'cpalius', 'url' => '', 'visits' => 3]],
            'chart' => ['type' => 'line', 'labels' => ['Mon'], 'pageviews' => [1], 'visits' => [1], 'visitors' => [1]],
        ]]);
        self::assertStringContainsString('01:02:05', $card);
        self::assertStringContainsString('data-visitorstats-chart="yandex"', $card);
        self::assertStringContainsString('cpalius', $card);

        $empty = $twig->render('@VisitorStatsModule/admin/_yandex.html.twig', ['yandex' => ['period' => 'daily', 'valid' => false]]);
        self::assertStringContainsString('<ol', $empty);

        /** @var \Modules\VisitorStats\Controller\Admin\YandexMetricaAdminController $screen */
        $screen = $this->container()->get(\Modules\VisitorStats\Controller\Admin\YandexMetricaAdminController::class);
        $screen->setContainer($this->container());
        self::assertSame(200, $screen->index()->getStatusCode());
    }

    public function testTrackerSnippetAndCspHostsFollowTheSettings(): void
    {
        $this->container();
        $this->pushRequest();
        /** @var YandexMetricaSettings $settings */
        $settings = $this->container()->get(YandexMetricaSettings::class);
        /** @var YandexTracker $tracker */
        $tracker = $this->container()->get(YandexTracker::class);
        /** @var YandexCspSources $csp */
        $csp = $this->container()->get(YandexCspSources::class);

        try {
            $settings->reset();
            self::assertSame('', $tracker->html(), 'no counter, no snippet');
            self::assertSame([], $csp->cspSources());

            $settings->update(['counter_id' => '98765', 'dispatch_ecommerce' => true, 'ecommerce_container_name' => 'dl', 'tracker_address' => 'https://cdn.example.test/tag.js']);

            $html = $tracker->html();
            self::assertStringContainsString('ym(98765, "init"', $html);
            self::assertStringContainsString('"https:\/\/cdn.example.test\/tag.js"', $html);
            self::assertStringContainsString('ecommerce: "dl"', $html);
            self::assertStringContainsString('https://mc.yandex.ru/watch/98765', $html);
            self::assertContains('https://cdn.example.test', $csp->cspSources()['script-src']);
            self::assertContains('wss://mc.yandex.ru', $csp->cspSources()['connect-src']);

            $settings->update(['new_yandex_code' => false]);
            self::assertStringContainsString('new Ya.Metrika', $tracker->html());

            // The access token is sealed at rest.
            $settings->update(['access_token' => 'plain-token']);
            $stored = (string) $this->container()->get('doctrine.dbal.default_connection')->fetchOne('SELECT setting_value FROM cp_settings WHERE setting_key = ?', [YandexMetricaSettings::KEY]);
            self::assertStringNotContainsString('plain-token', $stored);
            self::assertSame('plain-token', $settings->accessToken());

            // The core header policy picks the hosts up through the tag.
            $header = $this->container()->get(SecurityHeaderPolicy::class)->headers(Request::create('/'), 'n0nce');
            $csp = implode(' ', $header);
            self::assertStringContainsString('https://mc.yandex.ru', $csp);
        } finally {
            $settings->reset();
        }
    }
}
