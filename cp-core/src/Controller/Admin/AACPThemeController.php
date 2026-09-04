<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Core\Cache\CacheRebuildManager;
use App\Core\Annotation\CpAdminMenu;
use App\Core\Theme\ThemeDefinition;
use App\Core\Theme\ThemeRegistry;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

/**
 * Theme management screen: lists installed themes and switches the active one.
 * Twig namespaces are compiled, so activation is followed by a cache rebuild.
 */
final class AACPThemeController
{
    private const CSRF_TOKEN_ID = 'aacp_themes';

    public function __construct(
        private readonly Environment $twig,
        private readonly CsrfTokenManagerInterface $csrfTokenManager,
        private readonly ThemeRegistry $themeRegistry,
        private readonly CacheRebuildManager $cacheRebuildManager,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route('/aacp/themes', name: 'aacp_themes', methods: ['GET'])]
    #[CpAdminMenu(label: 'aacp.menu.themes', icon: 'heroicons:swatch', panel: 'aacp', priority: 70, capability: 'system.settings.manage', group: 'aacp.group.appearance')]
    #[IsGranted('system.settings.manage')]
    public function index(Request $request): Response
    {
        $active = $this->themeRegistry->active();

        $html = $this->twig->render('aacp/themes.html.twig', [
            'themes' => $this->themeRegistry->all(),
            'activeDirName' => $active?->dirName,
            'csrf_token' => $this->csrfTokenManager->getToken(self::CSRF_TOKEN_ID)->getValue(),
            'activated' => $request->query->getBoolean('activated'),
            'error' => $request->query->get('error'),
        ]);

        return new Response($html);
    }

    #[Route('/aacp/themes/{dirName}/activate', name: 'aacp_themes_activate', methods: ['POST'], requirements: ['dirName' => '[A-Za-z0-9_-]+'])]
    #[IsGranted('system.settings.manage')]
    public function activate(string $dirName, Request $request): RedirectResponse
    {
        $this->assertValidCsrf($request);

        $error = $this->themeRegistry->activate($dirName);

        if ($error !== null) {
            return new RedirectResponse('/aacp/themes?error='.urlencode($error));
        }

        // Theme view paths live in the compiled container; without this rebuild the
        // front end would keep rendering the previous theme's templates.
        $this->cacheRebuildManager->clearSymfonyCache();

        return new RedirectResponse('/aacp/themes?activated=1');
    }

    /**
     * Theme options screen. Kept separate from activation so appearance settings can
     * grow without touching the switcher.
     */
    #[Route('/aacp/themes/options', name: 'aacp_theme_options', methods: ['GET'])]
    #[CpAdminMenu(label: 'aacp.menu.theme_options', icon: 'heroicons:adjustments-horizontal', panel: 'aacp', priority: 71, capability: 'system.settings.manage', parent: 'aacp_themes')]
    #[IsGranted('system.settings.manage')]
    public function options(): Response
    {
        $active = $this->themeRegistry->active();

        $html = $this->twig->render('aacp/theme_options.html.twig', [
            'theme' => $active,
            'assets' => $active instanceof ThemeDefinition ? $this->describeAssets($active) : [],
        ]);

        return new Response($html);
    }

    /**
     * Lists the theme's declared entry files and whether each one exists on disk,
     * which is the fastest way to spot a theme shipped without its build output.
     *
     * @return list<array{type: string, path: string, exists: bool}>
     */
    private function describeAssets(ThemeDefinition $theme): array
    {
        $assets = [];

        foreach (['css' => $theme->css, 'js' => $theme->js] as $type => $paths) {
            foreach ($paths as $path) {
                $assets[] = [
                    'type' => $type,
                    'path' => $path,
                    'exists' => $this->themeRegistry->assetPath($theme, $path) !== null,
                ];
            }
        }

        return $assets;
    }

    private function assertValidCsrf(Request $request): void
    {
        $submitted = (string) $request->request->get('_token');

        if (!$this->csrfTokenManager->isTokenValid(new CsrfToken(self::CSRF_TOKEN_ID, $submitted))) {
            throw new BadRequestHttpException($this->translator->trans('aacp.system.invalid_csrf'));
        }
    }
}
