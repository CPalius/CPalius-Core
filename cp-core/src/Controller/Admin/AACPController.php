<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Core\Aacp\SystemWidgetData;
use App\Core\Aacp\SystemWidgetProviderInterface;
use App\Core\Annotation\CpAdminMenu;
use App\Core\Annotation\CpSetting;
use App\Core\Api\ApiKeyService;
use App\Core\Cache\CacheRebuildManager;
use App\Core\Cron\CronManager;
use App\Core\Hook\HookManager;
use App\Core\Module\ActiveModulesFileWriter;
use App\Core\Module\ModuleActivator;
use App\Core\Module\ModuleRegistry;
use App\Core\Plugin\PluginInterface;
use App\Core\Plugin\PluginRegistry;
use App\Core\Plugin\PluginToggleRepository;
use App\Core\Settings\SettingsRegistry;
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
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

/**
 * AACP: sistem çöktüğünde bile ayakta kalması gereken kurtarma konsolu.
 *
 * Bilinçli olarak sade tutulur: AbstractController'ın twig/router kısayolları
 * yerine servisler doğrudan enjekte edilir, böylece bu kontrolcü modül
 * katmanındaki bir hatadan (ör. bozuk bir servis tanımı) mümkün olduğunca
 * izole kalır.
 */
final class AACPController
{
    public function __construct(
        private readonly ModuleRegistry $moduleRegistry,
        private readonly ActiveModulesFileWriter $activeModulesFileWriter,
        private readonly ModuleActivator $moduleActivator,
        private readonly Connection $connection,
        private readonly Environment $twig,
        private readonly CsrfTokenManagerInterface $csrfTokenManager,
        private readonly string $projectDir,
        private readonly string $recoveryToken,
        private readonly SettingsRegistry $settingsRegistry,
        private readonly SystemSettingsService $systemSettingsService,
        private readonly SettingRepository $settingRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly LoggerInterface $logger,
        #[TaggedIterator('cpalius.aacp.system_widget_provider')]
        private readonly iterable $systemWidgetProviders,
        private readonly PluginRegistry $pluginRegistry,
        private readonly PluginToggleRepository $pluginToggleRepository,
        private readonly CacheRebuildManager $cacheRebuildManager,
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
    ) {
    }

    /**
     * Manifesto Law 2.3 (Safe Mode & Recovery Console): normal form_login
     * akışı veritabanına (User provider) bağımlıdır — DB tamamen erişilemez
     * olduğunda bu akış işe yaramaz. Bu uç, security firewall'ında BİLİNÇLİ
     * olarak PUBLIC_ACCESS'tir (bkz. security.yaml) ve yetkilendirmesini
     * KENDİSİ, .env'deki AACP_RECOVERY_TOKEN ile sabit zamanlı (timing-safe)
     * karşılaştırma yaparak yapar — hiçbir Doctrine sorgusu içermez, bu
     * yüzden veritabanı çökmüşken bile çalışır.
     *
     * Token boşsa (yani .env'de tanımlanmamışsa) kapı tamamen kapalıdır:
     * "token yok" asla "herkese izin ver" anlamına gelmez (fail-safe).
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
     * Kurtarma modundan modül devre dışı bırakma: aynı deactivateModule()
     * mantığı ama sonrasında normal /aacp yerine token'ı koruyarak
     * /aacp/recovery'ye geri döner (aksi halde firewall onu /login'e atar).
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
     * Genel Bakış (Dashboard): eskiden ayrı olan "Sistem Monitörü"
     * (/aacp/system) ile burada BİRLEŞTİRİLDİ — ikisi de aynı canlı sistem
     * verisini (buildSystemReport) farklı sunumlarla gösteriyordu, bu da
     * yönetici için iki ayrı ekranda aynı bilgiyi arama kafa karışıklığına
     * yol açıyordu. Artık AACP'nin TEK giriş noktası burası: hem modül
     * sağlığı/karantina hem de htop tarzı canlı metrikler (load, memory,
     * OPcache, DB, queue) aynı sayfada, Chart.js destekli metrik
     * kartlarıyla gösterilir.
     */
    #[Route('/aacp', name: 'aacp_dashboard', methods: ['GET'])]
    #[CpAdminMenu(label: 'aacp.menu.dashboard', icon: 'heroicons:chart-bar', panel: 'aacp', priority: 10, capability: 'system.aacp.access', group: 'aacp.group.system')]
    #[IsGranted('system.aacp.access')]
    public function dashboard(): Response
    {
        $health = $this->buildHealthReport();
        $system = $this->buildSystemReport();
        $modules = $this->moduleRegistry->discoverAllModules();

        $html = $this->twig->render('aacp/dashboard.html.twig', [
            'health' => $health,
            'system' => $system,
            'content' => $this->buildContentReport(),
            'infrastructure' => $this->buildInfrastructureReport(),
            'moduleStats' => $this->buildModuleStatsReport($modules),
            'criticalAlerts' => $this->buildCriticalAlerts($health, $system),
            'widgetCatalog' => $this->buildWidgetCatalog(),
            'hiddenWidgetIds' => $this->getHiddenWidgetIdsForCurrentUser(),
            'modules' => $modules,
            'csrf_token' => $this->csrfTokenManager->getToken('aacp_module_deactivate')->getValue(),
            'widget_visibility_csrf_token' => $this->csrfTokenManager->getToken('aacp_widget_visibility')->getValue(),
        ]);

        return new Response($html);
    }

    /**
     * Dashboard'daki widget aç/kapa panelinin AJAX ucu: kalıcılık
     * User::$data['dashboard_widgets']['hidden'] JSON alanında tutulur
     * (bkz. User::getDataValue()/setDataValue() — first_name/bio/
     * avatar_asset_id ile aynı desen, migration gerekmez). Anlık DOM
     * güncellemesi zaten JS tarafında yapılıyor; bu uç sadece tercihi
     * kalıcılaştırır.
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

    #[Route('/aacp/modules', name: 'aacp_modules', methods: ['GET'])]
    #[CpAdminMenu(label: 'aacp.menu.modules', icon: 'heroicons:puzzle-piece', panel: 'aacp', priority: 30, capability: 'system.module.manage', group: 'aacp.group.tools')]
    #[IsGranted('system.module.manage')]
    public function modules(Request $request): Response
    {
        $html = $this->twig->render('aacp/modules.html.twig', [
            'modules' => $this->moduleRegistry->discoverAllModules(),
            'csrf_token' => $this->csrfTokenManager->getToken('aacp_module_deactivate')->getValue(),
            'activated' => $request->query->getBoolean('activated'),
            'activationFailed' => $request->query->getBoolean('activation_failed'),
        ]);

        return new Response($html);
    }

    /**
     * Faz 3'te kurulan Modül Eklentisi (Plugin) mimarisinin yönetim
     * ekranı. isActive() FİLTRESİ OLMADAN TÜM plugin'ler listelenir
     * (PluginRegistry::getActivePlugins() DEĞİL) — aksi halde yönetici,
     * zaten AACP'den pasif ettiği bir eklentiyi listede hiç göremez ve
     * tekrar aktive edemezdi. Aktif/pasif durumu ayrıca
     * PluginToggleRepository::findAllStates() ile TEK sorguda okunup
     * şablona geçirilir.
     */
    #[Route('/aacp/plugins', name: 'aacp_plugins', methods: ['GET'])]
    #[CpAdminMenu(label: 'aacp.menu.plugins', icon: 'heroicons:puzzle-piece', panel: 'aacp', priority: 35, capability: 'system.module.manage', group: 'aacp.group.tools')]
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
     * Faz 4'ün AJAX ucu: sayfa yeniden yüklenmeden tek bir plugin'in
     * aktif/pasif durumunu değiştirir. CSRF token'ı, AACP'nin diğer
     * mutasyon action'larıyla ('aacp_module_deactivate') AYNI token id'yi
     * paylaşır — ekstra bir token türü icat edilmez, tüm AACP mutasyon
     * formları/AJAX çağrıları zaten bu tek token'ı kullanıyor.
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

    /** Legacy route: module settings are now the "Modules" tab of /aacp/settings. */
    #[Route('/aacp/settings/modules', name: 'aacp_settings_modules', methods: ['GET'])]
    #[IsGranted('system.settings.manage')]
    public function settingsModules(): RedirectResponse
    {
        return new RedirectResponse('/aacp/settings?tab='.CpSetting::SCOPE_MODULE);
    }

    /** Legacy route: plugin settings are now the "Plugins" tab of /aacp/settings. */
    #[Route('/aacp/settings/plugins', name: 'aacp_settings_plugins', methods: ['GET'])]
    #[IsGranted('system.settings.manage')]
    public function settingsPlugins(): RedirectResponse
    {
        return new RedirectResponse('/aacp/settings?tab='.CpSetting::SCOPE_PLUGIN);
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

            // FAZ 4: çevrilebilir ayar iki boyutlu gelir (settings[key][dil])
            // ve tek bir JSON dil haritası olarak saklanır. Kodlama
            // mantığı SystemSettingsService ile PAYLAŞILIR — iki ayar
            // ekranının aynı veriyi farklı biçimlerde yazması, sessiz bir
            // veri bozulması kaynağı olurdu.
            if ($definition->isTranslatable()) {
                $encoded = $this->systemSettingsService->encodeTranslationMap($raw);

                if ($encoded !== null) {
                    $keysToTouch[] = $definition->key;
                    $pendingValues[$definition->key] = [$definition, $encoded];
                }

                continue;
            }

            $value = match ($definition->type) {
                // HTML formlarında işaretsiz bir checkbox HİÇ gönderilmez;
                // bu yüzden "anahtar yok" burada "false" anlamına gelir.
                'checkbox' => $raw !== null ? '1' : '0',
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
        return str_starts_with($candidate, '/aacp/settings') ? $candidate : '/aacp/settings';
    }

    /**
     * "integer" tipi #[CpSetting] alanları için sıfır tolerans validasyonu:
     * yalnızca (isteğe bağlı işaretli) tam sayı string'leri kabul edilir —
     * "10.5", "abc", "" veya baştaki/sondaki boşluklu varyasyonlar reddedilir.
     */
    private function isValidInteger(string $value): bool
    {
        return preg_match('/^-?\d+$/', $value) === 1;
    }

    /**
     * htop panelinin AJAX/polling ile periyodik tazelemesi için sade JSON
     * ucu. Şablonun tamamını yeniden render etmek yerine sadece canlı
     * metrikleri döner; Dashboard sayfasındaki fetch+DOM/Chart.js güncellemesi
     * bunu tüketir (Zero Node.js / Zero build-step: düz importmap JS).
     *
     * NOT: eskiden ayrı bir sayfası olan "Sistem Monitörü" (/aacp/system)
     * Genel Bakış (Dashboard) ile birleştirildiği için bu uç artık
     * dashboard.html.twig'in canlı polling hedefidir (bkz. dashboard() ve
     * aacp-dashboard.js).
     */
    #[Route('/aacp/system/metrics', name: 'aacp_system_metrics', methods: ['GET'])]
    #[IsGranted('system.aacp.access')]
    public function systemMetrics(): Response
    {
        $system = $this->buildSystemReport();
        $alerts = $this->buildCriticalAlerts($this->buildHealthReport(), $system);

        // JS'in Twig |trans filtresine erişimi yok — aynı desen
        // PerformanceController::translateResult()'ta da kullanılıyor:
        // mesaj sunucu tarafında, isteğin gerçek locale'ine göre çevrilip
        // JSON'a hazır metin olarak konur.
        $system['criticalAlerts'] = array_map(
            fn (array $alert): array => $alert + ['message' => $this->translator->trans($alert['messageKey'], $alert['params'])],
            $alerts,
        );

        return new Response(
            json_encode($system, JSON_THROW_ON_ERROR),
            200,
            ['Content-Type' => 'application/json'],
        );
    }

    /**
     * "Önbellek ve Yeniden Derleme" konsolu — CacheRebuildManager'ın üç
     * bağımsız işlemini (Symfony cache, OPcache, Tailwind asset rebuild)
     * tetikleyen AJAX butonlarını barındıran sayfa. Sayfanın kendisi
     * hiçbir işlemi otomatik ÇALIŞTIRMAZ (salt-okunur GET) — mutasyonlar
     * yalnızca aşağıdaki üç POST ucundan, kullanıcının bilinçli tıklamasıyla
     * tetiklenir.
     *
     * "Performans" (bkz. PerformanceController::index()'teki 'aacp.group.performance'
     * ile aynı grup adı) BİLİNÇLİ OLARAK bir $parent İLE DEĞİL, düz bir
     * $group başlığıyla kurulur: bu sayfa ile RMVP sayfası, "Sistem"/
     * "Araçlar" gruplarındaki diğer öğelerle simetrik şekilde, aynı
     * seviyede iki ayrı üst-seviye link olarak yan yana listelenir (bkz.
     * AdminMenuRuntime::render() — group sadece bir ayırıcı başlık,
     * kendi başına tıklanabilir bir node değildir).
     */
    #[Route('/aacp/system/cache-rebuild', name: 'aacp_cache_rebuild', methods: ['GET'])]
    #[CpAdminMenu(label: 'aacp.menu.performance_cp_care', icon: 'heroicons:arrow-path', panel: 'aacp', priority: 26, capability: 'system.aacp.access', group: 'aacp.group.performance')]
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

        return new JsonResponse($this->cacheRebuildManager->clearSymfonyCache());
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
     * Üç cache-rebuild AJAX ucunun paylaştığı TEK CSRF token id'si
     * ('aacp_cache_rebuild') — cache_rebuild.html.twig sayfasında bir kez
     * üretilip her üç butonun isteğine de aynı token gömülür (bkz.
     * cacheRebuild() action'ının render ettiği csrf_token değişkeni).
     */
    private function isValidCacheRebuildToken(Request $request): bool
    {
        $submitted = (string) $request->request->get('_token');

        return $this->csrfTokenManager->isTokenValid(new CsrfToken('aacp_cache_rebuild', $submitted));
    }

    #[Route('/aacp/modules/{dirName}/deactivate', name: 'aacp_module_deactivate', methods: ['POST'])]
    #[IsGranted('system.module.manage')]
    public function deactivateModule(string $dirName, Request $request): RedirectResponse
    {
        $submittedToken = (string) $request->request->get('_token');
        if (!$this->csrfTokenManager->isTokenValid(new CsrfToken('aacp_module_deactivate', $submittedToken))) {
            throw new BadRequestHttpException($this->translator->trans('aacp.system.invalid_csrf'));
        }

        // Goes through ModuleActivator so dependents are checked and, when the
        // caller asks for it, the module's uninstall() hook runs first.
        $result = $this->moduleActivator->deactivate($dirName, $request->request->getBoolean('purge'));

        $redirectTo = (string) $request->request->get('_redirect', '/aacp');
        $target = str_starts_with($redirectTo, '/aacp') ? $redirectTo : '/aacp';

        if (!$result['success']) {
            $separator = str_contains($target, '?') ? '&' : '?';

            return new RedirectResponse($target.$separator.'module_error='.urlencode($result['message']));
        }

        return new RedirectResponse($target);
    }

    /**
     * Bir modülü web arayüzünden aktive eder. CLI'daki cp:module:activate
     * ile BİREBİR aynı ModuleActivator servisini kullanır — dry-run/lint
     * doğrulaması (Manifesto Law 2.2) burada da atlanmaz, aksi halde
     * bozuk bir modül web'den aktive edilip container derlemesini
     * çökertebilirdi. Doğrulama cache:clear + lint:yaml + lint:container
     * çalıştırdığı için bu istek birkaç saniye sürebilir.
     */
    #[Route('/aacp/modules/{dirName}/activate', name: 'aacp_module_activate', methods: ['POST'])]
    #[IsGranted('system.module.manage')]
    public function activateModule(string $dirName, Request $request): RedirectResponse
    {
        $submittedToken = (string) $request->request->get('_token');
        if (!$this->csrfTokenManager->isTokenValid(new CsrfToken('aacp_module_deactivate', $submittedToken))) {
            throw new BadRequestHttpException($this->translator->trans('aacp.system.invalid_csrf'));
        }

        $result = $this->moduleActivator->activate($dirName);

        $redirectTo = (string) $request->request->get('_redirect', '/aacp');
        $target = str_starts_with($redirectTo, '/aacp') ? $redirectTo : '/aacp';
        $separator = str_contains($target, '?') ? '&' : '?';

        return new RedirectResponse($target.$separator.($result['success'] ? 'activated=1' : 'activation_failed=1'));
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
     * Dashboard "İçerik" bölümünün tek veri kaynağı — hepsi DB-count/SUM/
     * GROUP BY sorguları, bilinçli olarak /aacp/system/metrics polling'ine
     * DAHİL EDİLMEZ (nadiren değişen sayılar için 4 saniyede bir sorgu
     * atmanın maliyeti yok): sadece tam sayfa yüklemesinde hesaplanır.
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
     * Dashboard "Altyapı" bölümü — Cron/Hook/API/Dil sayıları. Aynı
     * gerekçeyle (nadiren değişir) polling'e dahil edilmez.
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
     * Dashboard "Güvenlik/Modül" bölümündeki modül versiyon dağılımı ve
     * durum dağılımı grafiklerinin veri kaynağı. discoverAllModules()
     * zaten dashboard()'da bir kez çağrılmış oluyor, burada tekrar
     * sorgu atılmaz — sonuç parametre olarak alınır.
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
     * Kritik Uyarı Şeridi'nin veri kaynağı — SADECE bugün gerçekten
     * ölçülebilen koşullardan (karantina/DB/OPcache zaten hesaplanmış
     * $health/$system'den, ardışık cron hatası yeni bir sorgudan) üretilir.
     * Uydurma bir "exception_rate" veya "queue.failed_count" burada YOKTUR
     * — bunlar sistemde hiç ölçülmüyor (bkz. readQueueStatus() docblock'u).
     *
     * $health, buildHealthReport()'un; $system, buildSystemReport()'un
     * dönüş değeridir (bu metodun kendisi tekrar sorgu atmaz).
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
     * Widget aç/kapa panelindeki checkbox listesi VE her kartın
     * data-widget-id'si için TEK doğruluk kaynağı — burası değişmeden bir
     * widget "yeni" eklenemez, drift önlenir.
     *
     * @return list<array{widgetId: string, titleKey: string, section: string}>
     */
    private function buildWidgetCatalog(): array
    {
        return [
            ['widgetId' => 'system.load', 'titleKey' => 'aacp.system.load_average', 'section' => 'system'],
            ['widgetId' => 'system.memory', 'titleKey' => 'aacp.system.php_memory', 'section' => 'system'],
            ['widgetId' => 'system.opcache', 'titleKey' => 'OPcache', 'section' => 'system'],
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
     * @return array<string, true> widgetId'ye göre O(1) arama için
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
     * htop tarzı canlı sistem raporu. Tamamen salt-okunur ve yan etkisiz:
     * hiçbir metrik toplama işlemi state değiştirmez, bu yüzden bu metod
     * hem tam sayfa render'ında hem de /aacp/system/metrics JSON ucunda
     * güvenle tekrar tekrar çağrılabilir.
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
        return [
            'load' => $this->readLoadAverage(),
            'memory' => $this->readMemoryUsage(),
            'opcache' => $this->readOpcacheStatus(),
            'database' => $this->readDatabaseStatus(),
            'queue' => $this->readQueueStatus(),
            'quarantine' => $this->readQuarantinedModules(),
            'moduleWidgets' => $this->collectModuleWidgets(),
            'generatedAt' => (new \DateTimeImmutable())->format(DATE_ATOM),
        ];
    }

    /**
     * Etiketlenmiş (cpalius.aacp.system_widget_provider) tüm modül
     * provider'larını dolaşıp verilerini toplar. Manifesto Law 2.3 (Safe
     * Mode & Recovery Console) ruhuyla HER provider ayrı ayrı try/catch
     * içine alınır: bir modülün widget'ı (ör. henüz migrate edilmemiş bir
     * tablo yüzünden) hata fırlatırsa sadece o kart atlanır ve loglanır —
     * tek bir bozuk modül /aacp/system sayfasının tamamını 500'e düşürmez.
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
                $this->logger->warning('AACP sistem widget sağlayıcısı başarısız oldu, atlanıyor.', [
                    'provider' => $provider::class,
                    'exception' => $e->getMessage(),
                ]);
            }
        }

        return $widgets;
    }

    /**
     * @return array{available: bool, one: ?float, five: ?float, fifteen: ?float}
     */
    private function readLoadAverage(): array
    {
        // sys_getloadavg() Windows'ta desteklenmez ve false döner; bu durumu
        // hata olarak değil "bu platformda mevcut değil" olarak ele alıyoruz.
        $load = \function_exists('sys_getloadavg') ? sys_getloadavg() : false;

        if ($load === false) {
            return ['available' => false, 'one' => null, 'five' => null, 'fifteen' => null];
        }

        return [
            'available' => true,
            'one' => round($load[0], 2),
            'five' => round($load[1], 2),
            'fifteen' => round($load[2], 2),
        ];
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
     * php.ini "memory_limit" string'ini byte'a çevirir. "-1" (sınırsız)
     * için null döner — gauge'un paydası olamayacağı için bu durum ayrı
     * ele alınır (bkz. readMemoryUsage()'daki null-check).
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
                'message' => 'OPcache eklentisi bu PHP kurulumunda yüklü değil.',
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
                'message' => 'OPcache yüklü ama devre dışı (opcache.enable=0 olabilir).',
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
     * @return array{connected: bool, error: ?string, platform: ?string}
     */
    private function readDatabaseStatus(): array
    {
        try {
            $this->connection->executeQuery('SELECT 1');

            return [
                'connected' => true,
                'error' => null,
                'platform' => $this->connection->getDatabasePlatform()::class,
            ];
        } catch (DBALException $e) {
            return [
                'connected' => false,
                'error' => $e->getMessage(),
                'platform' => null,
            ];
        }
    }

    /**
     * symfony/messenger bu kurulu (manifesto "Zero
     * Node.js" ruhuna paralel olarak çekirdek de gereksiz bağımlılık
     * biriktirmez). Kurulana kadar bu panel dürüstçe "kurulu değil" der;
     * sahte bir sayı uydurmak yerine kartın kendisi bunu açıkça belirtir.
     *
     * @return array{available: bool, message: string, pending: ?int}
     */
    private function readQueueStatus(): array
    {
        if (!interface_exists(\Symfony\Component\Messenger\MessageBusInterface::class)) {
            return [
                'available' => false,
                'message' => 'symfony/messenger kurulu değil. Kuyruk izleme için "composer require symfony/messenger" gerekir.',
                'pending' => null,
            ];
        }

        return [
            'available' => true,
            'message' => 'Messenger algılandı.',
            'pending' => null,
        ];
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

        // En yeni kayıt en üstte görünsün.
        return array_reverse(array_values($lines));
    }
}
