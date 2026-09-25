<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Controller\Admin\AACPController;
use App\Core\Admin\PlatformPulseService;
use App\Core\Settings\SettingsRegistry;
use App\Entity\Node;
use App\Tests\Support\IntegrationTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The AACP command desk after it stopped being a website dashboard.
 *
 * Two properties are held here. First, the platform sections actually reach
 * the screen an operator opens — a read model nobody renders is not a feature.
 * Second, the live traffic feed is capped: it used to render thirty rows and
 * pushed every platform signal below the fold, which is the whole reason this
 * work exists, so the cap is asserted against a table that has more rows
 * available than the cap allows.
 */
#[CoversClass(PlatformPulseService::class)]
#[CoversClass(AACPController::class)]
final class AacpPlatformPulseTest extends IntegrationTestCase
{
    public function testEverySectionIsReadableOnAWorkingInstallation(): void
    {
        $this->container();
        $this->pushRequest();
        $this->authenticateAs('admin');
        $this->forgetPulseCache();

        $pulse = $this->pulse()->build();

        foreach (['work', 'faults', 'integrity', 'volume'] as $section) {
            self::assertTrue(
                $pulse[$section]['available'],
                sprintf('the "%s" section reads its sources without falling back', $section),
            );
        }

        // The integrity panel prints when it last checked rather than implying
        // it is live, because the underlying doctor run is cached.
        self::assertNotEmpty($pulse['integrity']['checkedAt']);
        self::assertArrayHasKey('pass', $pulse['integrity']['summary']);
    }

    public function testDataVolumeIsDrivenByTheEntityTypeRegistry(): void
    {
        $this->container();
        $this->pushRequest();
        $this->authenticateAs('admin');
        $this->forgetPulseCache();

        $ids = array_column($this->pulse()->build()['volume']['types'], 'id');

        // Nothing in the service names an entity type. These two appear
        // because they carry #[CpEntityType]; a CRM module's Contact would
        // appear the same way, without core being taught about it.
        self::assertContains('node', $ids);
        self::assertContains('user', $ids);
    }

    public function testSoftDeletedRowsAreNotCountedAsData(): void
    {
        $this->container();
        $this->pushRequest();
        $this->authenticateAs('admin');

        $em = $this->em();

        $em->persist(new Node('Live record', 'live-record', 'post', 'tr'));

        $em->persist((new Node('Trashed record', 'trashed-record', 'post', 'tr'))->softDelete());

        $em->flush();

        $this->forgetPulseCache();

        $counts = [];
        foreach ($this->pulse()->build()['volume']['types'] as $type) {
            $counts[$type['id']] = $type['count'];
        }

        // A soft-deleted row is in the bin, not in the business. Counting it
        // would make the panel disagree with every list screen in the panel.
        self::assertSame(1, $counts['node'] ?? null);
    }

    public function testTheCommandDeskRendersThePlatformPanels(): void
    {
        $this->container();
        $this->pushRequest();
        $this->authenticateAs('admin');
        $this->forgetPulseCache();

        $html = (string) $this->controller()->dashboard()->getContent();

        foreach ([
            'aacp.pulse.work.title',
            'aacp.pulse.faults.title',
            'aacp.pulse.integrity.title',
            'aacp.pulse.volume.title',
        ] as $key) {
            self::assertStringContainsString(
                $this->trans($key),
                $html,
                sprintf('"%s" is on the page, not just in the read model', $key),
            );
        }
    }

    public function testTheLiveFeedIsCappedAtTenRows(): void
    {
        $this->container();
        $this->pushRequest();
        $this->authenticateAs('admin');
        $this->forgetPulseCache();

        // Page views are counted, not logged as rows (VisitorStatsRecorder),
        // so the visitor-mode dashboard no longer has a per-row feed to cap —
        // only the security feed still lists individual rows.
        $this->enableSecurityScanner();

        // Deliberately more than the cap: a table with nine rows would pass
        // this assertion whatever the limit was.
        $this->seedTelemetryRows(18);

        $html = (string) $this->controller()->dashboard()->getContent();

        self::assertSame(
            10,
            substr_count($html, 'data-telemetry-id="'),
            'the feed is a pulse, not a log; the log has its own screen',
        );

        // The client trims to the same budget, read from the server rather
        // than from a second constant that could drift.
        self::assertStringContainsString('data-aacp-telemetry-rows="10"', $html);
    }

    public function testTheVisitorTopPagesAndTopVisitorPanelsAreGone(): void
    {
        $this->container();
        $this->pushRequest();
        $this->authenticateAs('admin');
        $this->forgetPulseCache();

        $this->seedTelemetryRows(3);

        $html = (string) $this->controller()->dashboard()->getContent();

        // These answered a website's questions. This desk summarises the
        // CPalius core, so they were removed rather than shrunk — and the
        // polling hook has to go with them, or the client would keep asking
        // for a list nothing renders.
        self::assertStringNotContainsString($this->trans('aacp.telemetry.visitors.top_pages'), $html);
        self::assertStringNotContainsString($this->trans('aacp.telemetry.visitors.top_ips'), $html);
        self::assertStringNotContainsString('data-telemetry-top-pages', $html);
    }

    public function testEveryPanelCarriesACollapseIdentity(): void
    {
        $this->container();
        $this->pushRequest();
        $this->authenticateAs('admin');
        $this->forgetPulseCache();

        $html = (string) $this->controller()->dashboard()->getContent();

        // A panel without an id gets no toggle, and would be the one an
        // operator cannot fold away.
        foreach ([
            'telemetry.feed',
            'platform.work',
            'platform.faults',
            'platform.integrity',
            'platform.volume',
            'telemetry.trend',
            'system.subsystems',
            'system.performance',
        ] as $widgetId) {
            self::assertStringContainsString(
                'data-aacp-widget="'.$widgetId.'"',
                $html,
                sprintf('"%s" can be collapsed', $widgetId),
            );
        }

        self::assertStringContainsString('data-aacp-widget-csrf="', $html, 'the fold is persisted through a guarded POST');
    }

    public function testAFoldedPanelIsRenderedFoldedRatherThanRestoredByScript(): void
    {
        $this->container();
        $this->pushRequest();
        $user = $this->authenticateAs('admin');
        $this->forgetPulseCache();

        $user->setDataValue('dashboard_widgets', ['hidden' => ['platform.volume']]);
        $this->em()->flush();

        $html = (string) $this->controller()->dashboard()->getContent();

        // Server-rendered, so the panel the operator folded away does not
        // flash open on every load before a script gets to it.
        self::assertStringContainsString(
            'class="aacp-cmd-panel is-collapsed" data-aacp-widget="platform.volume"',
            $html,
        );
        self::assertStringNotContainsString(
            'class="aacp-cmd-panel is-collapsed" data-aacp-widget="platform.work"',
            $html,
            'only the chosen panel folds',
        );
    }

    public function testTheFoldIsPersistedPerUserThroughAGuardedPost(): void
    {
        $this->container();
        $this->pushRequest();
        $user = $this->authenticateAs('admin');

        /** @var CsrfTokenManagerInterface $csrf */
        $csrf = $this->container()->get('security.csrf.token_manager');

        $request = new Request(request: [
            '_token' => $csrf->getToken('aacp_widget_visibility')->getValue(),
            'widgetId' => 'platform.faults',
            'hidden' => '1',
        ]);
        $request->setMethod('POST');

        $response = $this->controller()->updateWidgetVisibility($request);
        self::assertSame(200, $response->getStatusCode());

        $this->em()->refresh($user);
        self::assertSame(
            ['hidden' => ['platform.faults']],
            $user->getDataValue('dashboard_widgets'),
        );

        // Without the token, a crafted page could rearrange somebody's panel
        // layout on their behalf. Cosmetic, but still theirs.
        $forged = new Request(request: ['_token' => 'forged', 'widgetId' => 'platform.faults', 'hidden' => '1']);
        $forged->setMethod('POST');
        self::assertSame(400, $this->controller()->updateWidgetVisibility($forged)->getStatusCode());
    }

    /**
     * Runtime counterpart to TemplateScriptNonceTest.
     *
     * That test greps shipped templates, so it can only see <script> tags an
     * author typed. The tags that actually break strict mode are the ones
     * nobody typed: importmap() emits three inline scripts of its own, and a
     * Twig component or bundle can add more. This renders the real page and
     * checks every executable script element the response contains.
     */
    public function testEveryExecutableScriptOnTheRenderedDeskCarriesTheNonce(): void
    {
        $this->container();
        $this->pushRequest();
        $this->authenticateAs('admin');
        $this->forgetPulseCache();

        $html = (string) $this->controller()->dashboard()->getContent();

        preg_match_all('/<script\b[^>]*>/i', $html, $matches);
        self::assertNotEmpty($matches[0], 'the desk ships JavaScript; a page with none would pass vacuously');

        foreach ($matches[0] as $tag) {
            // Data blocks are never prepared for execution, so CSP never
            // checks them (same exemption TemplateScriptNonceTest makes).
            if (preg_match('/type\s*=\s*"(application\/json|application\/ld\+json)"/i', $tag) === 1) {
                continue;
            }

            self::assertMatchesRegularExpression(
                '/\snonce="[^"]+"/',
                $tag,
                'without a nonce this tag is dead the moment an operator turns strict CSP on: '.$tag,
            );
        }
    }

    /**
     * The stylesheet must reach the page as a <link>, never as a JS import.
     *
     * AssetMapper resolves `import './styles/app.css'` to an importmap entry
     * served from a `data:application/javascript,...` URL. Our script-src
     * allows 'self', the nonce and https: — deliberately not data:, which is a
     * known XSS bypass — so that import is refused. And a refused STATIC
     * import rejects the whole module graph: one blocked stylesheet shim took
     * down every entrypoint on the page, charts and panel controls included.
     *
     * Merely appearing in the importmap is harmless (an entry nothing imports
     * is never fetched), so this asserts the two things that actually decide
     * it: the sheet is linked, and the entrypoint imports no CSS.
     */
    public function testTheStylesheetIsLinkedRatherThanImportedFromJavaScript(): void
    {
        $this->container();
        $this->pushRequest();
        $this->authenticateAs('admin');
        $this->forgetPulseCache();

        $html = (string) $this->controller()->dashboard()->getContent();

        self::assertMatchesRegularExpression(
            '/<link rel="stylesheet" href="[^"]*app[^"]*\.css"/',
            $html,
            'the desk carries its own stylesheet link',
        );

        $entrypoint = file_get_contents(\dirname(__DIR__, 2).'/assets/app.js');
        self::assertIsString($entrypoint);
        self::assertDoesNotMatchRegularExpression(
            "/^\s*import\s+['\"][^'\"]+\.css['\"]/m",
            $entrypoint,
            'a CSS import here is refused by CSP and takes every other entrypoint down with it',
        );
    }

    private function enableSecurityScanner(): void
    {
        $connection = $this->em()->getConnection();
        $connection->executeStatement(
            'INSERT INTO cp_settings (setting_key, setting_value) VALUES (:key, :value)
             ON DUPLICATE KEY UPDATE setting_value = :value',
            ['key' => 'telemetry.security_enabled', 'value' => '1'],
        );

        /** @var SettingsRegistry $settingsRegistry */
        $settingsRegistry = $this->container()->get(SettingsRegistry::class);
        $settingsRegistry->clearCache();
    }

    private function seedTelemetryRows(int $count): void
    {
        $connection = $this->em()->getConnection();

        for ($i = 0; $i < $count; ++$i) {
            // event_type must be page_view: with the security scanner off
            // (the default) the feed renders public traffic only.
            $connection->insert('cp_system_telemetry_logs', [
                'created_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
                'severity' => 'info',
                'event_type' => 'page_view',
                'ip_address' => '203.0.113.'.($i % 250),
                'request_method' => 'GET',
                'request_uri' => '/record/'.$i,
                'user_agent' => 'phpunit',
                'threat_score' => 0,
                'details' => '[]',
            ]);
        }
    }

    /**
     * The volume and integrity sections are cached on purpose (a doctor run
     * per dashboard load would be wrong). Tests assert against fresh reads.
     */
    private function forgetPulseCache(): void
    {
        /** @var CacheItemPoolInterface $cache */
        $cache = $this->container()->get('cache.app');
        $cache->deleteItem('aacp.pulse.volume');
        $cache->deleteItem('aacp.pulse.integrity');
    }

    private function pulse(): PlatformPulseService
    {
        /** @var PlatformPulseService $service */
        $service = $this->container()->get(PlatformPulseService::class);

        return $service;
    }

    private function controller(): AACPController
    {
        /** @var AACPController $controller */
        $controller = $this->container()->get(AACPController::class);

        return $controller;
    }

    private function trans(string $key): string
    {
        /** @var TranslatorInterface $translator */
        $translator = $this->container()->get(TranslatorInterface::class);

        return $translator->trans($key);
    }
}
