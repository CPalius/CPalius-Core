<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Core\Security\Reauth\RequiresRecentAuth;
use App\Core\Annotation\CpAdminMenu;
use App\Core\Cache\CacheRebuildManager;
use App\Core\Extension\ExtensionCatalogue;
use App\Core\Extension\ExtensionInstaller;
use App\Core\Module\ModuleActivator;
use App\Core\Module\ModulePackageService;
use App\Core\Theme\ThemeRegistry;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The store: every module and theme in one place. Browse the signed catalogue, install the free
 * ones, update, switch on or off, remove, and see a preview of each.
 */
#[IsGranted('system.module.manage')]
final class AACPStoreController extends AbstractController
{
    private const CSRF = 'aacp_store';

    public function __construct(
        private readonly ExtensionCatalogue $catalogue,
        private readonly ExtensionInstaller $installer,
        private readonly ModuleActivator $moduleActivator,
        private readonly ModulePackageService $modulePackages,
        private readonly ThemeRegistry $themes,
        private readonly CacheRebuildManager $cache,
        private readonly TranslatorInterface $translator,
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
    ) {
    }

    #[Route('/aacp/store', name: 'aacp_store', methods: ['GET'])]
    #[CpAdminMenu(label: 'aacp.store.menu', icon: 'heroicons:squares-plus', panel: 'aacp', priority: 2, capability: 'system.module.manage', parent: 'aacp_modules')]
    public function index(Request $request): Response
    {
        return $this->renderStore($request, 'modules');
    }

    /** The same store opened on themes; lives under the Appearance menu. */
    #[Route('/aacp/themes/store', name: 'aacp_theme_store', methods: ['GET'])]
    #[CpAdminMenu(label: 'aacp.store.theme_menu', icon: 'heroicons:squares-plus', panel: 'aacp', priority: 72, capability: 'system.module.manage', parent: 'aacp_themes')]
    public function themeStore(Request $request): Response
    {
        return $this->renderStore($request, 'themes');
    }

    private function renderStore(Request $request, string $defaultTab): Response
    {
        $this->catalogue->refreshIfStale();

        $tab = \in_array($request->query->getString('type'), ['all', 'modules', 'themes', 'updates'], true) ? $request->query->getString('type') : $defaultTab;
        $show = \in_array($request->query->getString('show'), ['all', 'installed', 'available'], true) ? $request->query->getString('show') : 'all';
        $q = mb_strtolower(trim($request->query->getString('q')));

        $entries = match ($tab) {
            'modules' => $this->catalogue->entries('modules'),
            'themes' => $this->catalogue->entries('themes'),
            default => array_merge($this->catalogue->entries('modules'), $this->catalogue->entries('themes')),
        };

        if ($tab === 'updates') {
            $entries = array_filter($entries, static fn (array $e): bool => $e['status'] === 'update');
        }

        if ($show === 'installed') {
            $entries = array_filter($entries, static fn (array $e): bool => $e['state'] !== 'not_installed');
        } elseif ($show === 'available') {
            $entries = array_filter($entries, static fn (array $e): bool => $e['state'] === 'not_installed');
        }

        if ($q !== '') {
            $entries = array_filter($entries, static fn (array $e): bool => str_contains(mb_strtolower($e['title'].' '.$e['summary'].' '.$e['name']), $q));
        }

        usort($entries, static fn (array $a, array $b): int => strcasecmp($a['title'], $b['title']));

        return $this->render('aacp/store/index.html.twig', [
            'tab' => $tab,
            'show' => $show,
            'q' => $request->query->getString('q'),
            'entries' => $entries,
            'updateCount' => $this->catalogue->updateCount(),
            'checkedAt' => $this->catalogue->checkedAt(),
            'securityError' => $this->catalogue->securityError(),
            'hasIndex' => $this->catalogue->checkedAt() !== null,
            'activeTheme' => $this->themes->activeDirName(),
        ]);
    }

    #[Route('/aacp/store/screenshot/{type}/{name}', name: 'aacp_store_screenshot', methods: ['GET'], requirements: ['type' => 'modules|themes', 'name' => '[A-Za-z][A-Za-z0-9_-]*'])]
    public function screenshot(string $type, string $name): Response
    {
        $path = $this->catalogue->screenshotPath($type, $name);

        if ($path === null) {
            throw new NotFoundHttpException();
        }

        $response = new BinaryFileResponse($path);
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Content-Security-Policy', "default-src 'none'; style-src 'unsafe-inline'; img-src data:; sandbox");
        $response->setMaxAge(300);

        return $response;
    }

    #[Route('/aacp/store/refresh', name: 'aacp_store_refresh', methods: ['POST'])]
    public function refresh(Request $request): RedirectResponse
    {
        $this->assertToken($request);
        $line = $this->catalogue->refresh();
        $this->addFlash(str_contains($line, 'updated') ? 'store_ok' : 'store_error', $line);

        return $this->redirectToRoute('aacp_store');
    }

    #[Route('/aacp/store/install', name: 'aacp_store_install', methods: ['POST'])]
    #[RequiresRecentAuth]
    public function install(Request $request): RedirectResponse
    {
        $this->assertToken($request);

        $type = $request->request->getString('type');
        $name = $request->request->getString('name');
        $result = $this->installer->install($type, $name);

        if (!$result['success']) {
            $this->addFlash('store_error', $this->translator->trans('aacp.store.flash.failed', ['reason' => $result['message']]));

            return $this->redirectToRoute('aacp_store');
        }

        $this->addFlash('store_ok', $this->translator->trans($result['wasUpdate'] ? 'aacp.store.flash.updated' : 'aacp.store.flash.downloaded', ['name' => $name]));

        // An active module's update finishes on the updates screen (hooks, module upgrade, close maintenance).
        if ($result['needsApply']) {
            $this->addFlash('auto_apply', '1');

            return $this->redirectToRoute('aacp_updates_index');
        }

        return $this->redirectToRoute('aacp_store');
    }

    /**
     * Updates every module and theme that has a newer catalogue version. An active module's update ends on the
     * updates screen (one at a time), so the run stops there and reports how many are left.
     */
    #[Route('/aacp/store/update-all', name: 'aacp_store_update_all', methods: ['POST'])]
    #[RequiresRecentAuth]
    public function updateAll(Request $request): RedirectResponse
    {
        $this->assertToken($request);
        $this->catalogue->refreshIfStale();

        $pending = [];
        foreach (['modules', 'themes'] as $type) {
            foreach ($this->catalogue->entries($type) as $entry) {
                if ($entry['status'] === 'update') {
                    $pending[] = [$type, (string) $entry['name']];
                }
            }
        }

        $done = 0;
        foreach ($pending as $i => [$type, $name]) {
            $result = $this->installer->install($type, $name);

            if (!$result['success']) {
                $this->addFlash('store_error', $this->translator->trans('aacp.store.flash.failed', ['reason' => $name.': '.$result['message']]));

                continue;
            }

            ++$done;

            if ($result['needsApply']) {
                $left = \count($pending) - $i - 1;
                $this->addFlash('store_ok', $this->translator->trans('aacp.store.flash.update_all_partial', ['count' => $done, 'left' => $left]));
                $this->addFlash('auto_apply', '1');
                // The updates screen offers to carry on with the rest once this one is applied.
                $request->getSession()->set('store_update_all_left', $left);

                return $this->redirectToRoute('aacp_updates_index');
            }
        }

        if ($done > 0) {
            $this->addFlash('store_ok', $this->translator->trans('aacp.store.flash.update_all', ['count' => $done]));
        }

        return $this->redirectToRoute('aacp_store');
    }

    /**
     * Switch a module or theme on or off, or remove it from the system.
     */
    #[Route('/aacp/store/action', name: 'aacp_store_action', methods: ['POST'])]
    #[RequiresRecentAuth]
    public function action(Request $request): RedirectResponse
    {
        $this->assertToken($request);

        $type = $request->request->getString('type');
        $name = $request->request->getString('name');
        $action = $request->request->getString('action');
        $purge = $request->request->getBoolean('purge');

        if (!\in_array($type, ['modules', 'themes'], true) || preg_match('/^[A-Za-z][A-Za-z0-9_-]*$/', $name) !== 1) {
            throw new BadRequestHttpException('Unknown extension.');
        }

        $result = match (true) {
            $type === 'modules' && $action === 'activate' => $this->moduleActivator->activate($name),
            $type === 'modules' && $action === 'deactivate' => $this->moduleActivator->deactivate($name, false),
            $type === 'modules' && $action === 'delete' => $this->modulePackages->delete($name, $purge),
            $type === 'themes' && $action === 'activate' => $this->activateTheme($name),
            $type === 'themes' && $action === 'delete' => $this->deleteTheme($name),
            default => throw new BadRequestHttpException('Unknown action.'),
        };

        if (!$result['success']) {
            $this->addFlash('store_error', (string) $result['message']);
        } else {
            $key = ($result['pending'] ?? false) ? 'activating' : $action;
            $this->addFlash('store_ok', $this->translator->trans('aacp.store.flash.'.$key, ['name' => $name]));
        }

        return $this->redirectToRoute('aacp_store', ['type' => $request->request->getString('back', 'all')]);
    }

    /**
     * @return array{success: bool, message: string}
     */
    private function activateTheme(string $name): array
    {
        $error = $this->themes->activate($name);

        if ($error !== null) {
            return ['success' => false, 'message' => $error];
        }

        // Theme view paths live in the compiled container.
        $this->cache->clearSymfonyCache();

        return ['success' => true, 'message' => 'ok'];
    }

    /**
     * @return array{success: bool, message: string}
     */
    private function deleteTheme(string $name): array
    {
        if ($name === $this->themes->activeDirName() || $name === ThemeRegistry::FALLBACK_THEME) {
            return ['success' => false, 'message' => $this->translator->trans('aacp.store.flash.theme_in_use')];
        }

        $dir = $this->projectDir.'/cp-content/themes/'.$name;

        if (!is_dir($dir)) {
            return ['success' => false, 'message' => 'Theme not found.'];
        }

        $fs = new Filesystem();
        $fs->remove([$dir, $this->projectDir.'/public/themes/'.$name]);
        $this->cache->clearSymfonyCache();

        return ['success' => true, 'message' => 'ok'];
    }

    private function assertToken(Request $request): void
    {
        if (!$this->isCsrfTokenValid(self::CSRF, (string) $request->request->get('_token'))) {
            throw new BadRequestHttpException($this->translator->trans('aacp.common.error.invalid_csrf'));
        }
    }
}
