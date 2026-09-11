<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Core\Aacp\SystemWidgetData;
use App\Core\Aacp\SystemWidgetProviderInterface;
use App\Core\Annotation\CpAdminMenu;
use App\Core\Api\ApiKeyService;
use App\Core\Audit\Entity\AuditLog;
use App\Core\Audit\Repository\AuditLogRepository;
use App\Core\Cache\CacheRebuildManager;
use App\Core\Cache\OptionalRedis;
use App\Core\Cron\CronManager;
use App\Core\Hook\HookManager;
use App\Core\Module\ActiveModulesFileWriter;
use App\Core\Module\ModuleRegistry;
use App\Core\Performance\PerformanceInventory;
use App\Core\Plugin\PluginInterface;
use App\Core\Plugin\PluginRegistry;
use App\Core\Plugin\PluginToggleRepository;
use App\Core\Queue\QueueStatusService;
use App\Core\Security\Repository\TelemetryLogRepository;
use App\Core\Settings\SettingsRegistry;
use App\Core\Settings\SettingSecretCodec;
use App\Core\Settings\SystemSettingsService;
use App\Entity\CronJob;
use App\Entity\Setting;
use App\Entity\User;
use App\Repository\AssetRepository;
use App\Repository\CategoryRepository;
use App\Repository\CronJobRunRepository;
use App\Repository\LocaleRepository;
use App\Repository\NodeRepository;
use App\Repository\SettingRepository;
use App\Repository\TagRepository;
use App\Repository\UserRepository;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception as DBALException;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\Attribute\TaggedIterator;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

/**
 * AACP recovery console that must stay up when the system fails.
 * Services are injected directly to stay isolated from module-layer failures.
 */
final class AACPController
{
    public function __construct(
        private readonly ModuleRegistry $moduleRegistry,
        private readonly ActiveModulesFileWriter $activeModulesFileWriter,
        private readonly Connection $connection,
        private readonly Environment $twig,
        private readonly CsrfTokenManagerInterface $csrfTokenManager,
        private readonly string $projectDir,
        private readonly string $recoveryToken,
        private readonly SettingsRegistry $settingsRegistry,
        private readonly SystemSettingsService $systemSettingsService,
        private readonly SettingSecretCodec $settingSecretCodec,
        private readonly SettingRepository $settingRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly LoggerInterface $logger,
        #[TaggedIterator('cpalius.aacp.system_widget_provider')]
        private readonly iterable $systemWidgetProviders,
        private readonly PluginRegistry $pluginRegistry,
        private readonly PluginToggleRepository $pluginToggleRepository,
        private readonly CacheRebuildManager $cacheRebuildManager,
        private readonly QueueStatusService $queueStatusService,
        private readonly NodeRepository $nodeRepository,
        private readonly CategoryRepository $categoryRepository,
        private readonly TagRepository $tagRepository,
        private readonly AssetRepository $assetRepository,
        private readonly UserRepository $userRepository,
        private readonly LocaleRepository $localeRepository,
        private readonly CronManager $cronManager,
        private readonly CronJobRunRepository $cronJobRunRepository,
        private readonly HookManager $hookManager,
        private readonly ApiKeyService $apiKeyService,
        private readonly Security $security,
        private readonly TranslatorInterface $translator,
        private readonly AuditLogRepository $auditLogRepository,
        private readonly TelemetryLogRepository $telemetryLogRepository,
        private readonly PerformanceInventory $performanceInventory,
        #[Autowire(service: 'cache.app')]
        private readonly CacheInterface $appCache,
        #[Autowire('%kernel.debug%')]
        private readonly bool $kernelDebug,
        #[Autowire('%env(MAILER_DSN)%')]
        private readonly string $mailerDsn,
        private readonly OptionalRedis $redis,
    ) {
    }

    /**
     * Manifesto Law 2.3: PUBLIC recovery gate using AACP_RECOVERY_TOKEN (no DB).
     * Empty token means closed — fail-safe, never open to everyone.
     */
    #[Route('/aacp/recovery', name: 'aacp_recovery', methods: ['GET'])]
    public function recovery(Request $request): Response
    {
        $submitted = (string) $request->query->get('token', '');

        if ($this->recoveryToken === '' || $submitted === '' || !hash_equals($this->recoveryToken, $submitted)) {
            throw new AccessDeniedHttpException($this->translator->trans('aacp.system.recovery_token_invalid'));
        }

        $html = $this->twig->render('aacp/recovery.html.twig', [
            'modules' => $this->moduleRegistry->discoverAllModules(),
            'token' => $submitted,
            'csrf_token' => $this->csrfTokenManager->getToken('aacp_module_deactivate')->getValue(),
        ]);

        return new Response($html);
    }

    /**
     * Deactivate a module from recovery mode; redirect back to /aacp/recovery with token.
     */
    #[Route('/aacp/recovery/modules/{dirName}/deactivate', name: 'aacp_recovery_module_deactivate', methods: ['POST'])]
    public function recoveryDeactivateModule(string $dirName, Request $request): RedirectResponse
    {
        $submittedToken = (string) $request->request->get('recovery_token', '');
        if ($this->recoveryToken === '' || $submittedToken === '' || !hash_equals($this->recoveryToken, $submittedToken)) {
            throw new AccessDeniedHttpException($this->translator->trans('aacp.system.recovery_token_invalid'));
        }

        $csrfToken = (string) $request->request->get('_token');
        if (!$this->csrfTokenManager->isTokenValid(new CsrfToken('aacp_module_deactivate', $csrfToken))) {
            throw new BadRequestHttpException($this->translator->trans('aacp.system.invalid_csrf'));
        }

        foreach ($this->moduleRegistry->discoverAllModules() as $module) {
            if ($module['dirName'] === $dirName && $module['class'] !== null) {
                $this->activeModulesFileWriter->remove($module['class']);
                break;
            }
        }

        return new RedirectResponse('/aacp/recovery?token='.urlencode($submittedToken));
    }

    /**
     * System command desk: health, telemetry, audit feed. No content metrics.
     */
    #[Route('/aacp', name: 'aacp_dashboard', methods: ['GET'])]
    #[CpAdminMenu(label: 'aacp.menu.dashboard', icon: 'heroicons:chart-bar', panel: 'aacp', priority: 10, capability: 'system.aacp.access', group: 'aacp.group.system')]
    #[IsGranted('system.aacp.access')]
    public function dashboard(): Response
    {
        $health = $this->buildHealthReport();
        $system = $this->buildSystemReport();
        $securityOn = (bool) $this->settingsRegistry->get('telemetry.security_enabled', false);
        $visitorStats = $securityOn
            ? [
                'uniqueIps' => 0,
                'pageViews' => 0,
                'hourly' => ['labels' => [], 'hits' => []],
                'topPages' => [],
                'topIps' => [],
            ]
            : $this->telemetryLogRepository->visitorStats(24);

        $html = $this->twig->render('aacp/dashboard.html.twig', [
            'health' => $health,
            'system' => $system,
            'auditFeed' => $this->buildAuditFeed(),
            'telemetrySecurityEnabled' => $securityOn,
            'telemetryFeed' => $this->telemetryLogRepository->findLiveFeed(30, null, !$securityOn),
            'telemetryTrend' => $securityOn
                ? $this->telemetryLogRepository->hourlyTrend(24)
                : $visitorStats['hourly'],
            'telemetryVectors' => $securityOn ? $this->telemetryLogRepository->vectorBreakdown(24) : [],
            'topThreatIps' => $securityOn ? $this->telemetryLogRepository->topThreatIps(5) : [],
            'visitorStats' => $visitorStats,
            'quarantineLog' => array_slice($this->readQuarantineLog(), 0, 8),
        ]);

        return new Response($html);
    }

    /**
     * AJAX endpoint persisting dashboard widget visibility in User::$data JSON.
     */
    #[Route('/aacp/dashboard/widget-visibility', name: 'aacp_dashboard_widget_visibility', methods: ['POST'])]
    #[IsGranted('system.aacp.access')]
    public function updateWidgetVisibility(Request $request): JsonResponse
    {
        $submittedToken = (string) $request->request->get('_token');
        if (!$this->csrfTokenManager->isTokenValid(new CsrfToken('aacp_widget_visibility', $submittedToken))) {
            return new JsonResponse(['success' => false, 'message' => $this->translator->trans('aacp.system.invalid_csrf')], 400);
        }

        $user = $this->security->getUser();
        if (!$user instanceof User) {
            return new JsonResponse(['success' => false, 'message' => $this->translator->trans('aacp.system.session_not_found')], 401);
        }

        $widgetId = (string) $request->request->get('widgetId', '');
        if ($widgetId === '' || preg_match('/^[a-z0-9_.]+$/', $widgetId) !== 1) {
            return new JsonResponse(['success' => false, 'message' => $this->translator->trans('aacp.system.invalid_widget_id')], 400);
        }

        $hidden = $request->request->getBoolean('hidden');

        $hiddenIds = $this->getHiddenWidgetIdsForUser($user);
        if ($hidden) {
            $hiddenIds[$widgetId] = true;
        } else {
            unset($hiddenIds[$widgetId]);
        }

        $user->setDataValue('dashboard_widgets', ['hidden' => array_keys($hiddenIds)]);
        $this->entityManager->flush();

        return new JsonResponse(['success' => true, 'hidden' => array_keys($hiddenIds)]);
    }

    /**
     * Lists all plugins (including inactive) so admins can re-enable them.
     * Toggle states come from PluginToggleRepository::findAllStates().
     */
    #[Route('/aacp/plugins', name: 'aacp_plugins', methods: ['GET'])]
    #[CpAdminMenu(label: 'aacp.menu.plugins', icon: 'heroicons:squares-plus', panel: 'aacp', priority: 31, capability: 'system.module.manage', parent: 'aacp_modules')]
    #[IsGranted('system.module.manage')]
    public function plugins(): Response
    {
        $states = $this->pluginToggleRepository->findAllStates();

        $plugins = [];
        foreach ($this->pluginRegistry->getAllPlugins() as $plugin) {
            $plugins[] = [
                'name' => $plugin->getName(),
                'label' => $plugin->getLabel(),
                'active' => $states[$plugin->getName()] ?? true,
            ];
        }

        $html = $this->twig->render('aacp/plugins.html.twig', [
            'plugins' => $plugins,
            'csrf_token' => $this->csrfTokenManager->getToken('aacp_module_deactivate')->getValue(),
        ]);

        return new Response($html);
    }

    /**
     * AJAX toggle for one plugin; shares CSRF token id aacp_module_deactivate.
     */
    #[Route('/aacp/plugins/{name}/toggle', name: 'aacp_plugin_toggle', methods: ['POST'])]
    #[IsGranted('system.module.manage')]
    public function togglePlugin(string $name, Request $request): JsonResponse
    {
        $submittedToken = (string) $request->request->get('_token');
        if (!$this->csrfTokenManager->isTokenValid(new CsrfToken('aacp_module_deactivate', $submittedToken))) {
            return new JsonResponse(['success' => false, 'message' => $this->translator->trans('aacp.system.invalid_csrf')], 400);
        }

        $plugin = $this->pluginRegistry->getPlugin($name);
        if (!$plugin instanceof PluginInterface) {
            return new JsonResponse(['success' => false, 'message' => $this->translator->trans('aacp.system.plugin_not_found')], 404);
        }

        $currentStates = $this->pluginToggleRepository->findAllStates();
        $newActiveState = !($currentStates[$name] ?? true);

        $this->pluginToggleRepository->setActive($name, $newActiveState);
        $this->entityManager->flush();

        return new JsonResponse(['success' => true, 'active' => $newActiveState]);
    }

    #[Route('/aacp/quarantine', name: 'aacp_quarantine', methods: ['GET'])]
    #[CpAdminMenu(label: 'aacp.menu.quarantine', icon: 'heroicons:shield-exclamation', panel: 'aacp', priority: 15, capability: 'system.module.manage', group: 'aacp.group.system')]
    #[IsGranted('system.module.manage')]
    public function quarantine(): Response
    {
        $html = $this->twig->render('aacp/quarantine.html.twig', [
            'quarantinedModules' => array_filter(
                $this->moduleRegistry->discoverAllModules(),
                static fn (array $m) => $m['status'] === 'quarantined',
            ),
            'logEntries' => $this->readQuarantineLog(),
            'csrf_token' => $this->csrfTokenManager->getToken('aacp_module_deactivate')->getValue(),
        ]);

        return new Response($html);
    }

    /** Legacy route: module settings live on the System Settings Modules tab. */
    #[Route('/aacp/settings/modules', name: 'aacp_settings_modules', methods: ['GET'])]
    #[IsGranted('system.settings.manage')]
    public function settingsModules(): RedirectResponse
    {
        return new RedirectResponse('/aacp/advanced/management?tab=modules');
    }

    /** Legacy route: plugin settings live on the System Settings Plugins tab. */
    #[Route('/aacp/settings/plugins', name: 'aacp_settings_plugins', methods: ['GET'])]
    #[IsGranted('system.settings.manage')]
    public function settingsPlugins(): RedirectResponse
    {
        return new RedirectResponse('/aacp/advanced/management?tab=plugins');
    }

    #[Route('/aacp/settings/update', name: 'aacp_settings_update', methods: ['POST'])]
    #[IsGranted('system.settings.manage')]
    public function updateSettings(Request $request): RedirectResponse
    {
        $submittedToken = (string) $request->request->get('_token');
        if (!$this->csrfTokenManager->isTokenValid(new CsrfToken('aacp_settings', $submittedToken))) {
            throw new BadRequestHttpException($this->translator->trans('aacp.system.invalid_csrf'));
        }

        /** @var array<string, string|array<string, string>> $submitted */
        $submitted = $request->request->all('settings');

        $definitions = $this->settingsRegistry->all();
        $keysToTouch = [];
        $pendingValues = [];

        foreach ($definitions as $definition) {
            $raw = $submitted[$definition->key] ?? null;

            // Phase 4: translatable settings as locale map JSON via SystemSettingsService.
            if ($definition->isTranslatable()) {
                $encoded = $this->systemSettingsService->encodeTranslationMap($raw);

                if ($encoded !== null) {
                    $keysToTouch[] = $definition->key;
                    $pendingValues[$definition->key] = [$definition, $encoded];
                }

                continue;
            }

            $value = match ($definition->type) {
                // Unchecked checkboxes are omitted from HTML forms — missing key means false.
                'checkbox' => $raw !== null ? '1' : '0',
                'password' => \is_string($raw) && trim($raw) !== '' ? $this->settingSecretCodec->seal(trim($raw)) : null,
                default => \is_string($raw) ? trim($raw) : null,
            };

            if ($value === null) {
                continue;
            }

            // A non-numeric "integer" input is never swallowed: bounce back to the
            // originating tab with the pre-submit values still in place.
            if ($definition->type === 'integer' && !$this->isValidInteger($value)) {
                $target = $this->safeRedirectTarget((string) $request->request->get('_redirect', ''));
                $separator = str_contains($target, '?') ? '&' : '?';

                return new RedirectResponse($target.$separator.'invalid_setting='.urlencode($definition->key));
            }

            if ($definition->type === 'select' && $definition->variants !== [] && !array_key_exists($value, $definition->variants)) {
                $target = $this->safeRedirectTarget((string) $request->request->get('_redirect', ''));
                $separator = str_contains($target, '?') ? '&' : '?';

                return new RedirectResponse($target.$separator.'invalid_setting='.urlencode($definition->key));
            }

            $keysToTouch[] = $definition->key;
            $pendingValues[$definition->key] = [$definition, $value];
        }

        $existing = $this->settingRepository->findIndexedByKeys($keysToTouch);

        foreach ($pendingValues as $key => [$definition, $value]) {
            $setting = $existing[$key] ?? null;
            if (!$setting instanceof Setting) {
                $setting = new Setting($definition->key, $definition->module);
                $this->entityManager->persist($setting);
            }

            $setting->setSettingValue($value);
        }

        $this->entityManager->flush();
        $this->settingsRegistry->clearCache();

        return new RedirectResponse($this->safeRedirectTarget((string) $request->request->get('_redirect', '')));
    }

    /** Open-redirect guard: only in-app settings paths are accepted. */
    private function safeRedirectTarget(string $candidate): string
    {
        if (str_starts_with($candidate, '/aacp/advanced/management')) {
            return $candidate;
        }

        return str_starts_with($candidate, '/aacp/settings') ? $candidate : '/aacp/advanced/management';
    }

    /**
     * Strict integer validation for #[CpSetting] integer fields (signed whole numbers only).
     */
    private function isValidInteger(string $value): bool
    {
        return preg_match('/^-?\d+$/', $value) === 1;
    }

    /**
     * JSON metrics for dashboard polling (aacp-dashboard.js); replaces old /aacp/system page.
     */
    #[Route('/aacp/system/metrics', name: 'aacp_system_metrics', methods: ['GET'])]
    #[IsGranted('system.aacp.access')]
    public function systemMetrics(): Response
    {
        $health = $this->buildHealthReport();
        $system = $this->buildSystemReport();
        $healthy = $health['dbConnected'] && $health['quarantinedModuleCount'] === 0;
        $statusKey = $health['dbConnected']
            ? ($healthy ? 'aacp.dashboard.status.healthy' : 'aacp.dashboard.status.degraded')
            : 'aacp.dashboard.status.down';

        $system['phpVersion'] = $health['phpVersion'];
        $system['statusLabel'] = $this->translator->trans($statusKey);
        $system['healthy'] = $healthy;

        return new Response(
            json_encode($system, JSON_THROW_ON_ERROR),
            200,
            ['Content-Type' => 'application/json'],
        );
    }

    /**
     * Cache rebuild console (GET only); three POST actions run CacheRebuildManager jobs.
     */
    #[Route('/aacp/system/cache-rebuild', name: 'aacp_cache_rebuild', methods: ['GET'])]
    #[CpAdminMenu(label: 'aacp.menu.performance_cp_care', icon: 'heroicons:arrow-path', panel: 'aacp', priority: 35, capability: 'system.aacp.access', group: 'aacp.group.performance')]
    #[IsGranted('system.aacp.access')]
    public function cacheRebuild(): Response
    {
        $html = $this->twig->render('aacp/cache_rebuild.html.twig', [
            'csrf_token' => $this->csrfTokenManager->getToken('aacp_cache_rebuild')->getValue(),
        ]);

        return new Response($html);
    }

    #[Route('/aacp/system/cache-rebuild/clear-symfony-cache', name: 'aacp_cache_rebuild_clear_symfony', methods: ['POST'])]
    #[IsGranted('system.aacp.access')]
    public function clearSymfonyCacheAction(Request $request): JsonResponse
    {
        if (!$this->isValidCacheRebuildToken($request)) {
            return new JsonResponse(['success' => false, 'output' => $this->translator->trans('aacp.system.invalid_csrf')], 400);
        }

        try {
            return new JsonResponse($this->cacheRebuildManager->clearSymfonyCache());
        } catch (\Throwable $e) {
            return new JsonResponse([
                'success' => false,
                'output' => '[ERR] '.$e->getMessage(),
            ]);
        }
    }

    #[Route('/aacp/system/cache-rebuild/reset-opcache', name: 'aacp_cache_rebuild_reset_opcache', methods: ['POST'])]
    #[IsGranted('system.aacp.access')]
    public function resetOpcacheAction(Request $request): JsonResponse
    {
        if (!$this->isValidCacheRebuildToken($request)) {
            return new JsonResponse(['success' => false, 'output' => $this->translator->trans('aacp.system.invalid_csrf')], 400);
        }

        return new JsonResponse($this->cacheRebuildManager->resetOpcache());
    }

    #[Route('/aacp/system/cache-rebuild/rebuild-assets', name: 'aacp_cache_rebuild_assets', methods: ['POST'])]
    #[IsGranted('system.aacp.access')]
    public function rebuildAssetsAction(Request $request): JsonResponse
    {
        if (!$this->isValidCacheRebuildToken($request)) {
            return new JsonResponse(['success' => false, 'output' => $this->translator->trans('aacp.system.invalid_csrf')], 400);
        }

        return new JsonResponse($this->cacheRebuildManager->rebuildAssets());
    }

    /**
     * Shared CSRF token id aacp_cache_rebuild for all three cache-rebuild POST actions.
     */
    private function isValidCacheRebuildToken(Request $request): bool
    {
        $submitted = (string) $request->request->get('_token');

        return $this->csrfTokenManager->isTokenValid(new CsrfToken('aacp_cache_rebuild', $submitted));
    }

    /**
     * @return array{
     *     phpVersion: string,
     *     dbConnected: bool,
     *     dbError: ?string,
     *     activeModuleCount: int,
     *     totalModuleCount: int,
     *     quarantinedModuleCount: int,
     *     moduleWidgets: list<SystemWidgetData>,
     * }
     */
    private function buildHealthReport(): array
    {
        $modules = $this->moduleRegistry->discoverAllModules();

        $dbConnected = true;
        $dbError = null;

        try {
            $this->connection->executeQuery('SELECT 1');
        } catch (DBALException $e) {
            $dbConnected = false;
            $dbError = $e->getMessage();
        }

        return [
            'phpVersion' => PHP_VERSION,
            'dbConnected' => $dbConnected,
            'dbError' => $dbError,
            'activeModuleCount' => count(array_filter($modules, static fn (array $m) => $m['status'] === 'active')),
            'totalModuleCount' => count($modules),
            'quarantinedModuleCount' => count(array_filter($modules, static fn (array $m) => $m['status'] === 'quarantined')),
            'moduleWidgets' => $this->collectModuleWidgets(),
        ];
    }

    /**
     * Dashboard content stats (full page load only, not live metrics polling).
     *
     * @return array{
     *     nodesTotal: int,
     *     nodesByType: list<array{type: string, count: int}>,
     *     nodesByStatus: list<array{status: string, count: int}>,
     *     categoriesTotal: int,
     *     tagsTotal: int,
     *     assetsTotal: int,
     *     assetsDiskUsageBytes: int,
     *     assetsByMimeType: list<array{mimeType: string, count: int}>,
     *     usersTotal: int,
     *     usersByStatus: list<array{status: string, count: int}>,
     * }
     */
    private function buildContentReport(): array
    {
        return [
            'nodesTotal' => $this->nodeRepository->countAll(),
            'nodesByType' => $this->nodeRepository->countGroupedByType(),
            'nodesByStatus' => $this->nodeRepository->countGroupedByStatus(),
            'categoriesTotal' => $this->categoryRepository->countAll(),
            'tagsTotal' => $this->tagRepository->countAll(),
            'assetsTotal' => $this->assetRepository->countAll(),
            'assetsDiskUsageBytes' => $this->assetRepository->sumFileSize(),
            'assetsByMimeType' => $this->assetRepository->countGroupedByMimeType(),
            'usersTotal' => $this->userRepository->countAll(),
            'usersByStatus' => $this->userRepository->countGroupedByStatus(),
        ];
    }

    /**
     * Dashboard infrastructure counts; excluded from live metrics polling.
     *
     * @return array{
     *     cronJobsTotal: int,
     *     hooksTotal: int,
     *     hooksByType: list<array{type: string, count: int}>,
     *     apiKeysActive: int,
     *     apiKeysInactive: int,
     *     localesActive: int,
     *     localesTotal: int,
     * }
     */
    private function buildInfrastructureReport(): array
    {
        $hooks = $this->hookManager->discoverAll();
        $hooksByType = [];
        foreach ($hooks as $hook) {
            $type = $hook['type'];
            $hooksByType[$type] = ($hooksByType[$type] ?? 0) + 1;
        }

        $apiKeys = $this->apiKeyService->findAll();
        $apiKeysActive = count(array_filter($apiKeys, static fn ($key) => $key->active));

        return [
            'cronJobsTotal' => count($this->cronManager->getTasks()),
            'hooksTotal' => count($hooks),
            'hooksByType' => array_map(
                static fn (string $type, int $count): array => ['type' => $type, 'count' => $count],
                array_keys($hooksByType),
                array_values($hooksByType),
            ),
            'apiKeysActive' => $apiKeysActive,
            'apiKeysInactive' => count($apiKeys) - $apiKeysActive,
            'localesActive' => $this->localeRepository->countActive(),
            'localesTotal' => $this->localeRepository->countAll(),
        ];
    }

    /**
     * Module version/status chart data; reuses $modules from discoverAllModules().
     *
     * @param list<array{dirName: string, name: string, version: string, class: ?string, status: string, reason: ?string}> $modules
     * @return array{
     *     versions: list<array{version: string, count: int}>,
     *     statusBreakdown: list<array{status: string, count: int}>,
     * }
     */
    private function buildModuleStatsReport(array $modules): array
    {
        $byVersion = [];
        $byStatus = [];
        foreach ($modules as $module) {
            $byVersion[$module['version']] = ($byVersion[$module['version']] ?? 0) + 1;
            $byStatus[$module['status']] = ($byStatus[$module['status']] ?? 0) + 1;
        }

        return [
            'versions' => array_map(
                static fn (string $version, int $count): array => ['version' => $version, 'count' => $count],
                array_keys($byVersion),
                array_values($byVersion),
            ),
            'statusBreakdown' => array_map(
                static fn (string $status, int $count): array => ['status' => $status, 'count' => $count],
                array_keys($byStatus),
                array_values($byStatus),
            ),
        ];
    }

    /**
     * Critical alert strip from measurable conditions only (no fake metrics).
     *
     * @return list<array{level: 'danger'|'warning', messageKey: string, params: array<string, mixed>}>
     */
    private function buildCriticalAlerts(array $health, array $system): array
    {
        $alerts = [];

        if ($health['quarantinedModuleCount'] > 0) {
            $alerts[] = [
                'level' => 'danger',
                'messageKey' => 'aacp.dashboard.alert.quarantined_modules',
                'params' => ['count' => $health['quarantinedModuleCount']],
            ];
        }

        if (!$system['database']['connected']) {
            $alerts[] = [
                'level' => 'danger',
                'messageKey' => 'aacp.dashboard.alert.database_down',
                'params' => [],
            ];
        }

        if ($system['opcache']['available'] && !$system['opcache']['enabled']) {
            $alerts[] = [
                'level' => 'warning',
                'messageKey' => 'aacp.dashboard.alert.opcache_disabled',
                'params' => [],
            ];
        }

        foreach ($this->cronManager->getTasks() as $task) {
            if (!$task instanceof CronJob) {
                continue;
            }

            $consecutiveFailures = $this->cronJobRunRepository->countConsecutiveFailures($task);
            if ($consecutiveFailures >= 3) {
                $alerts[] = [
                    'level' => 'warning',
                    'messageKey' => 'aacp.dashboard.alert.cron_failing',
                    'params' => ['jobName' => $task->getName(), 'count' => $consecutiveFailures],
                ];
            }
        }

        return $alerts;
    }

    /**
     * Single source of truth for dashboard widget ids and visibility toggles.
     *
     * @return list<array{widgetId: string, titleKey: string, section: string}>
     */
    private function buildWidgetCatalog(): array
    {
        return [
            ['widgetId' => 'system.load', 'titleKey' => 'aacp.system.load_average', 'section' => 'system'],
            ['widgetId' => 'system.memory', 'titleKey' => 'aacp.system.php_memory', 'section' => 'system'],
            ['widgetId' => 'system.opcache', 'titleKey' => 'aacp.dashboard.opcache', 'section' => 'system'],
            ['widgetId' => 'system.database', 'titleKey' => 'aacp.dashboard.database', 'section' => 'system'],
            ['widgetId' => 'system.queue', 'titleKey' => 'aacp.system.queue', 'section' => 'system'],
            ['widgetId' => 'system.quarantine_count', 'titleKey' => 'aacp.system.quarantine_console', 'section' => 'system'],
            ['widgetId' => 'system.php_version', 'titleKey' => 'aacp.dashboard.php_version', 'section' => 'system'],
            ['widgetId' => 'system.modules_active', 'titleKey' => 'aacp.dashboard.active_modules', 'section' => 'system'],

            ['widgetId' => 'content.nodes.total', 'titleKey' => 'aacp.dashboard.content.nodes_total', 'section' => 'content'],
            ['widgetId' => 'content.nodes.by_type', 'titleKey' => 'aacp.dashboard.content.nodes_by_type', 'section' => 'content'],
            ['widgetId' => 'content.nodes.by_status', 'titleKey' => 'aacp.dashboard.content.nodes_by_status', 'section' => 'content'],
            ['widgetId' => 'content.categories.total', 'titleKey' => 'aacp.dashboard.content.categories_total', 'section' => 'content'],
            ['widgetId' => 'content.tags.total', 'titleKey' => 'aacp.dashboard.content.tags_total', 'section' => 'content'],
            ['widgetId' => 'content.assets.total', 'titleKey' => 'aacp.dashboard.content.assets_total', 'section' => 'content'],
            ['widgetId' => 'content.assets.disk_usage', 'titleKey' => 'aacp.dashboard.content.assets_disk_usage', 'section' => 'content'],
            ['widgetId' => 'content.assets.by_mime', 'titleKey' => 'aacp.dashboard.content.assets_by_mime', 'section' => 'content'],
            ['widgetId' => 'content.users.total', 'titleKey' => 'aacp.dashboard.content.users_total', 'section' => 'content'],
            ['widgetId' => 'content.users.by_status', 'titleKey' => 'aacp.dashboard.content.users_by_status', 'section' => 'content'],

            ['widgetId' => 'security.api_keys.total', 'titleKey' => 'aacp.dashboard.security.api_keys', 'section' => 'security'],
            ['widgetId' => 'security.locales.active', 'titleKey' => 'aacp.dashboard.security.locales_active', 'section' => 'security'],
            ['widgetId' => 'security.hooks.total', 'titleKey' => 'aacp.dashboard.security.hooks_total', 'section' => 'security'],

            ['widgetId' => 'infra.cron.jobs_total', 'titleKey' => 'aacp.dashboard.infra.cron_jobs_total', 'section' => 'infrastructure'],
            ['widgetId' => 'infra.cron.recent_runs', 'titleKey' => 'aacp.dashboard.infra.cron_recent_runs', 'section' => 'infrastructure'],
        ];
    }

    /**
     * @return array<string, true> O(1) lookup by widgetId
     */
    private function getHiddenWidgetIdsForCurrentUser(): array
    {
        $user = $this->security->getUser();
        if (!$user instanceof User) {
            return [];
        }

        return $this->getHiddenWidgetIdsForUser($user);
    }

    /**
     * @return array<string, true>
     */
    private function getHiddenWidgetIdsForUser(User $user): array
    {
        $stored = $user->getDataValue('dashboard_widgets', ['hidden' => []]);
        $hidden = is_array($stored) ? ($stored['hidden'] ?? []) : [];

        return array_fill_keys(array_filter((array) $hidden, 'is_string'), true);
    }

    /**
     * Live read-only system report; safe to call from full page and /aacp/system/metrics.
     *
     * @return array{
     *     load: array{available: bool, one: ?float, five: ?float, fifteen: ?float},
     *     memory: array{phpUsageBytes: int, phpUsageMiB: float, phpPeakBytes: int, phpPeakMiB: float, limit: string},
     *     opcache: array{available: bool, enabled: bool, message: ?string, hitRate: ?float, usedMemoryMiB: ?float, freeMemoryMiB: ?float, wastedMemoryMiB: ?float, numCachedScripts: ?int},
     *     database: array{connected: bool, error: ?string, platform: ?string},
     *     queue: array{available: bool, message: string, pending: ?int},
     *     quarantine: list<array{dirName: string, name: string, reason: ?string}>,
     *     moduleWidgets: list<SystemWidgetData>,
     *     generatedAt: string,
     * }
     */
    private function buildSystemReport(): array
    {
        $database = $this->readDatabaseStatus();
        $opcache = $this->readOpcacheStatus();
        $memory = $this->readMemoryUsage();
        $queue = $this->readQueueStatus();

        return [
            'load' => $this->readLoadAverage(),
            'requestDurationMs' => $this->readRequestDurationMs(),
            'memory' => $memory,
            'opcache' => $opcache,
            'database' => $database,
            'queue' => $queue,
            'cache' => $this->readCacheStatus(),
            'mailer' => $this->readMailerStatus(),
            'cron' => $this->readCronStatus(),
            'uptime' => $this->readUptime(),
            'performance' => $this->readPerformanceInventory(),
            'safeMode' => $this->kernelDebug,
            'quarantine' => $this->readQuarantinedModules(),
            'moduleWidgets' => $this->collectModuleWidgets(),
            'generatedAt' => (new \DateTimeImmutable())->format(DATE_ATOM),
        ];
    }

    /**
     * Collects tagged system widget providers; each wrapped in try/catch (Law 2.3).
     *
     * @return list<SystemWidgetData>
     */
    private function collectModuleWidgets(): array
    {
        $widgets = [];

        foreach ($this->systemWidgetProviders as $provider) {
            if (!$provider instanceof SystemWidgetProviderInterface) {
                continue;
            }

            try {
                $widgets[] = $provider->getWidget();
            } catch (\Throwable $e) {
                $this->logger->warning('AACP system widget provider failed; skipping.', [
                    'provider' => $provider::class,
                    'exception' => $e->getMessage(),
                ]);
            }
        }

        return $widgets;
    }

    /**
     * @return array{available: bool, one: ?float, five: ?float, fifteen: ?float, label: ?string}
     */
    private function readLoadAverage(): array
    {
        // sys_getloadavg() is unavailable on Windows; treat that as "n/a", not an error.
        $load = \function_exists('sys_getloadavg') ? sys_getloadavg() : false;

        if ($load === false) {
            return ['available' => false, 'one' => null, 'five' => null, 'fifteen' => null, 'label' => null];
        }

        $one = round($load[0], 2);
        $five = round($load[1], 2);
        $fifteen = round($load[2], 2);

        return [
            'available' => true,
            'one' => $one,
            'five' => $five,
            'fifteen' => $fifteen,
            'label' => sprintf('%s / %s / %s', $one, $five, $fifteen),
        ];
    }

    /** Wall-clock time from request start to this report, in milliseconds. */
    private function readRequestDurationMs(): ?float
    {
        $start = $_SERVER['REQUEST_TIME_FLOAT'] ?? null;
        if (!\is_numeric($start)) {
            return null;
        }

        return round((microtime(true) - (float) $start) * 1000, 1);
    }

    /**
     * @return array{phpUsageBytes: int, phpUsageMiB: float, phpPeakBytes: int, phpPeakMiB: float, limit: string, memoryLimitBytes: ?int, memoryUsagePercent: ?float}
     */
    private function readMemoryUsage(): array
    {
        $usage = memory_get_usage(true);
        $peak = memory_get_peak_usage(true);
        $limit = (string) ini_get('memory_limit');
        $limitBytes = $this->parseIniMemoryValue($limit);

        return [
            'phpUsageBytes' => $usage,
            'phpUsageMiB' => round($usage / 1024 / 1024, 2),
            'phpPeakBytes' => $peak,
            'phpPeakMiB' => round($peak / 1024 / 1024, 2),
            'limit' => $limit,
            'memoryLimitBytes' => $limitBytes,
            'memoryUsagePercent' => $limitBytes !== null && $limitBytes > 0
                ? round(($peak / $limitBytes) * 100, 1)
                : null,
        ];
    }

    /**
     * Parses php.ini memory_limit to bytes; "-1" (unlimited) returns null.
     */
    private function parseIniMemoryValue(string $value): ?int
    {
        $value = trim($value);
        if ($value === '' || $value === '-1') {
            return null;
        }

        $unit = strtolower(substr($value, -1));
        $number = (int) $value;

        return match ($unit) {
            'g' => $number * 1024 * 1024 * 1024,
            'm' => $number * 1024 * 1024,
            'k' => $number * 1024,
            default => $number,
        };
    }

    /**
     * @return array{available: bool, enabled: bool, message: ?string, hitRate: ?float, usedMemoryMiB: ?float, freeMemoryMiB: ?float, wastedMemoryMiB: ?float, numCachedScripts: ?int}
     */
    private function readOpcacheStatus(): array
    {
        if (!\function_exists('opcache_get_status')) {
            return [
                'available' => false,
                'enabled' => false,
                'message' => $this->translator->trans('aacp.cache_rebuild.log.opcache_missing'),
                'hitRate' => null,
                'usedMemoryMiB' => null,
                'freeMemoryMiB' => null,
                'wastedMemoryMiB' => null,
                'numCachedScripts' => null,
            ];
        }

        $status = @opcache_get_status(false);

        if ($status === false) {
            return [
                'available' => true,
                'enabled' => false,
                'message' => $this->translator->trans('aacp.cache_rebuild.log.opcache_disabled'),
                'hitRate' => null,
                'usedMemoryMiB' => null,
                'freeMemoryMiB' => null,
                'wastedMemoryMiB' => null,
                'numCachedScripts' => null,
            ];
        }

        $memory = $status['memory_usage'] ?? [];
        $stats = $status['opcache_statistics'] ?? [];

        return [
            'available' => true,
            'enabled' => (bool) ($status['opcache_enabled'] ?? false),
            'message' => null,
            'hitRate' => isset($stats['opcache_hit_rate']) ? round((float) $stats['opcache_hit_rate'], 2) : null,
            'usedMemoryMiB' => isset($memory['used_memory']) ? round($memory['used_memory'] / 1024 / 1024, 2) : null,
            'freeMemoryMiB' => isset($memory['free_memory']) ? round($memory['free_memory'] / 1024 / 1024, 2) : null,
            'wastedMemoryMiB' => isset($memory['wasted_memory']) ? round($memory['wasted_memory'] / 1024 / 1024, 2) : null,
            'numCachedScripts' => $stats['num_cached_scripts'] ?? null,
        ];
    }

    /**
     * @return array{
     *     connected: bool,
     *     error: ?string,
     *     platform: ?string,
     *     latencyMs: ?float,
     *     version: ?string,
     *     name: ?string,
     *     charset: ?string,
     *     sizeLabel: ?string,
     *     tableCount: ?int,
     *     threadsConnected: ?int,
     *     maxConnections: ?int,
     *     slowQueries: ?int,
     *     serverUptime: ?string,
     * }
     */
    private function readDatabaseStatus(): array
    {
        $empty = [
            'connected' => false,
            'error' => null,
            'platform' => null,
            'latencyMs' => null,
            'version' => null,
            'name' => null,
            'charset' => null,
            'sizeLabel' => null,
            'tableCount' => null,
            'threadsConnected' => null,
            'maxConnections' => null,
            'slowQueries' => null,
            'serverUptime' => null,
        ];

        try {
            $started = hrtime(true);
            $this->connection->executeQuery('SELECT 1');
            $latencyMs = round((hrtime(true) - $started) / 1e6, 2);
            $size = $this->readDatabaseSize();

            return [
                'connected' => true,
                'error' => null,
                'platform' => $this->connection->getDatabasePlatform()::class,
                'latencyMs' => $latencyMs,
                'version' => $this->fetchScalar('SELECT VERSION()'),
                'name' => $this->fetchScalar('SELECT DATABASE()'),
                'charset' => $this->fetchScalar('SELECT @@character_set_database'),
                'sizeLabel' => $size['sizeLabel'],
                'tableCount' => $size['tableCount'],
                'threadsConnected' => $this->fetchMysqlShowInt('STATUS', 'Threads_connected'),
                'maxConnections' => $this->fetchMysqlShowInt('VARIABLES', 'max_connections'),
                'slowQueries' => $this->fetchMysqlShowInt('STATUS', 'Slow_queries'),
                'serverUptime' => $this->formatMysqlUptime($this->fetchMysqlShowInt('STATUS', 'Uptime')),
            ];
        } catch (DBALException $e) {
            $empty['error'] = $e->getMessage();

            return $empty;
        }
    }

    /**
     * @return array{tableCount: ?int, sizeLabel: ?string}
     */
    private function readDatabaseSize(): array
    {
        try {
            $row = $this->connection->fetchAssociative(
                'SELECT COUNT(*) AS table_count, ROUND(SUM(data_length + index_length) / 1024 / 1024, 2) AS size_mb
                 FROM information_schema.tables WHERE table_schema = DATABASE()',
            );
        } catch (\Throwable) {
            return ['tableCount' => null, 'sizeLabel' => null];
        }

        if (!\is_array($row)) {
            return ['tableCount' => null, 'sizeLabel' => null];
        }

        $tables = isset($row['table_count']) ? (int) $row['table_count'] : null;
        $sizeMb = isset($row['size_mb']) && is_numeric($row['size_mb']) ? (float) $row['size_mb'] : null;

        return [
            'tableCount' => $tables,
            'sizeLabel' => $sizeMb === null
                ? null
                : ($sizeMb >= 1024 ? round($sizeMb / 1024, 2).' GB' : round($sizeMb, 2).' MB'),
        ];
    }

    private function fetchScalar(string $sql): ?string
    {
        try {
            $value = $this->connection->fetchOne($sql);
        } catch (\Throwable) {
            return null;
        }

        if ($value === null || $value === false) {
            return null;
        }

        return (string) $value;
    }

    private function fetchMysqlShowInt(string $kind, string $name): ?int
    {
        if (preg_match('/^[A-Za-z_]+$/', $name) !== 1) {
            return null;
        }

        $sql = match ($kind) {
            'STATUS' => 'SHOW STATUS LIKE '.$this->connection->quote($name),
            'VARIABLES' => 'SHOW VARIABLES LIKE '.$this->connection->quote($name),
            default => null,
        };

        if ($sql === null) {
            return null;
        }

        try {
            $row = $this->connection->fetchAssociative($sql);
        } catch (\Throwable) {
            return null;
        }

        $raw = \is_array($row) ? ($row['Value'] ?? $row['value'] ?? null) : null;

        return is_numeric($raw) ? (int) $raw : null;
    }

    private function formatMysqlUptime(?int $seconds): ?string
    {
        if ($seconds === null || $seconds < 0) {
            return null;
        }

        return $this->formatDuration($seconds);
    }

    /**
     * @return array{available: bool, message: string, pending: ?int, messengerPending: int, platformPending: int, messengerFailed: int, platformFailed: int}
     */
    private function readQueueStatus(): array
    {
        try {
            $summary = $this->queueStatusService->summary();

            return [
                'available' => true,
                'message' => $this->translator->trans('aacp.dashboard.queue.dual_ok', [
                    'messenger' => $summary['messengerPending'],
                    'platform' => $summary['platformPending'],
                ]),
                'pending' => $summary['pending'],
                'messengerPending' => $summary['messengerPending'],
                'platformPending' => $summary['platformPending'],
                'messengerFailed' => $summary['messengerFailed'],
                'platformFailed' => $summary['platformFailed'],
            ];
        } catch (\Throwable) {
            return [
                'available' => false,
                'message' => $this->translator->trans('aacp.dashboard.queue.unavailable'),
                'pending' => null,
                'messengerPending' => 0,
                'platformPending' => 0,
                'messengerFailed' => 0,
                'platformFailed' => 0,
            ];
        }
    }

    /**
     * @return list<array{dirName: string, name: string, reason: ?string}>
     */
    private function readQuarantinedModules(): array
    {
        $quarantined = array_filter(
            $this->moduleRegistry->discoverAllModules(),
            static fn (array $m) => $m['status'] === 'quarantined',
        );

        return array_values(array_map(
            static fn (array $m) => ['dirName' => $m['dirName'], 'name' => $m['name'], 'reason' => $m['reason']],
            $quarantined,
        ));
    }

    /**
     * @return list<string>
     */
    private function readQuarantineLog(): array
    {
        $logFile = $this->projectDir.'/cp-core/var/log/module_quarantine.log';

        if (!is_file($logFile)) {
            return [];
        }

        $contents = file_get_contents($logFile);
        if ($contents === false || trim($contents) === '') {
            return [];
        }

        $lines = array_filter(array_map('trim', explode(PHP_EOL, $contents)));

        // Newest log entries first.
        return array_reverse(array_values($lines));
    }

    /**
     * @return array{seconds: ?int, label: string}
     */
    private function readUptime(): array
    {
        $seconds = null;
        if (is_readable('/proc/uptime')) {
            $raw = @file_get_contents('/proc/uptime');
            if (\is_string($raw) && $raw !== '') {
                $seconds = (int) floatval(explode(' ', $raw)[0]);
            }
        }
        if ($seconds === null && \function_exists('opcache_get_status')) {
            $status = @opcache_get_status(false);
            $start = is_array($status) ? ($status['opcache_statistics']['start_time'] ?? null) : null;
            if (is_numeric($start) && (int) $start > 0) {
                $seconds = max(0, time() - (int) $start);
            }
        }

        return [
            'seconds' => $seconds,
            'label' => $seconds === null ? '—' : $this->formatDuration($seconds),
        ];
    }

    private function formatDuration(int $seconds): string
    {
        $days = intdiv($seconds, 86400);
        $hours = intdiv($seconds % 86400, 3600);
        $minutes = intdiv($seconds % 3600, 60);

        if ($days > 0) {
            return sprintf('%dd %dh %dm', $days, $hours, $minutes);
        }
        if ($hours > 0) {
            return sprintf('%dh %dm', $hours, $minutes);
        }

        return sprintf('%dm', $minutes);
    }

    /**
     * @return array<string, mixed>
     */
    private function readPerformanceInventory(): array
    {
        try {
            return $this->appCache->get('aacp.performance.inventory', function ($item) {
                $item->expiresAfter(8);

                return $this->performanceInventory->snapshot();
            });
        } catch (\Throwable $e) {
            $this->logger->warning('Performance inventory failed.', ['exception' => $e->getMessage()]);

            try {
                return $this->performanceInventory->snapshot();
            } catch (\Throwable) {
                return [];
            }
        }
    }

    /**
     * @return array{redisOnline: bool, pool: string}
     */
    private function readCacheStatus(): array
    {
        $redisOnline = $this->redis->isAvailable();

        try {
            $this->appCache->get('aacp.dashboard.ping', static fn () => 'ok');
        } catch (\Throwable) {
            // Cache pool probe is best-effort.
        }

        $pool = (new \ReflectionClass($this->appCache))->getShortName();

        return [
            'redisOnline' => $redisOnline,
            'pool' => $pool,
        ];
    }

    /**
     * @return array{configured: bool, dsn: string}
     */
    private function readMailerStatus(): array
    {
        $dsn = trim($this->mailerDsn);
        $configured = $dsn !== '' && !str_starts_with($dsn, 'null://');
        $masked = preg_replace('#://([^:/@]+):([^@/]+)@#', '://$1:***@', $dsn) ?? $dsn;

        return [
            'configured' => $configured,
            'dsn' => $masked !== '' ? $masked : '—',
        ];
    }

    /**
     * @return array{lastRunAt: ?string, lastRunLabel: string}
     */
    private function readCronStatus(): array
    {
        $latest = null;
        foreach ($this->cronManager->getTasks() as $task) {
            if (!$task instanceof CronJob) {
                continue;
            }
            $runAt = $task->getLastRunAt();
            if ($runAt instanceof \DateTimeImmutable && ($latest === null || $runAt > $latest)) {
                $latest = $runAt;
            }
        }

        if ($latest === null) {
            $runs = $this->cronJobRunRepository->findLatest(1);
            $latest = $runs !== [] ? $runs[0]->getStartedAt() : null;
        }

        return [
            'lastRunAt' => $latest?->format(DATE_ATOM),
            'lastRunLabel' => $latest instanceof \DateTimeImmutable
                ? $latest->format('d.m.Y H:i')
                : $this->translator->trans('aacp.dashboard.cron_never'),
        ];
    }

    /**
     * @return list<array{time: string, eventKey: string, actor: string, ipStatus: string}>
     */
    private function buildAuditFeed(): array
    {
        $rows = [];

        try {
            foreach ($this->cronJobRunRepository->findLatest(10) as $run) {
                $success = $run->isSuccess();
                $rows[] = [
                    'sort' => $run->getStartedAt()->getTimestamp(),
                    'time' => $run->getStartedAt()->format('H:i:s'),
                    'eventKey' => 'aacp.dashboard.event.cron',
                    'actor' => $run->getCronJob()->getName(),
                    'ipStatus' => $success === false ? 'fail' : ($success === true ? 'ok' : 'running'),
                ];
            }
        } catch (\Throwable) {
            // Cron history table may be missing; the command desk still renders.
        }

        try {
            foreach ($this->auditLogRepository->findRecent(10) as $log) {
                $rows[] = [
                    'sort' => $log->getCreatedAt()->getTimestamp(),
                    'time' => $log->getCreatedAt()->format('H:i:s'),
                    'eventKey' => $this->auditEventKey($log),
                    'actor' => $this->auditActorName($log->getUserId()),
                    'ipStatus' => $log->getAction(),
                ];
            }
        } catch (\Throwable) {
            // cp_audit_logs may not be migrated yet; AACP must stay up.
        }

        usort($rows, static fn (array $a, array $b): int => $b['sort'] <=> $a['sort']);
        $rows = array_slice($rows, 0, 10);

        return array_map(static function (array $row): array {
            unset($row['sort']);

            return $row;
        }, $rows);
    }

    private function auditEventKey(AuditLog $log): string
    {
        $resource = strtolower($log->getResourceName());
        if (str_contains($resource, 'user')) {
            return 'aacp.dashboard.event.user_login';
        }
        if (str_contains($resource, 'setting')) {
            return 'aacp.dashboard.event.setting_change';
        }
        if (str_contains($resource, 'module')) {
            return 'aacp.dashboard.event.module_boot';
        }
        if (str_contains($resource, 'cache')) {
            return 'aacp.dashboard.event.cache_flush';
        }

        return match ($log->getAction()) {
            AuditLog::ACTION_CREATE => 'aacp.dashboard.event.create',
            AuditLog::ACTION_DELETE => 'aacp.dashboard.event.delete',
            default => 'aacp.dashboard.event.update',
        };
    }

    private function auditActorName(?int $userId): string
    {
        if ($userId === null) {
            return $this->translator->trans('aacp.dashboard.actor.system');
        }

        $user = $this->userRepository->find($userId);

        return $user instanceof User ? $user->getFullName() : '#'.$userId;
    }
}
