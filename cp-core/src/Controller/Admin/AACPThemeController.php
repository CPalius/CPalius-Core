<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Core\Annotation\CpAdminMenu;
use App\Core\Cache\CacheRebuildManager;
use App\Core\Theme\ThemeDefinition;
use App\Core\Theme\ThemeFileEditor;
use App\Core\Theme\ThemePackageService;
use App\Core\Theme\ThemeRegistry;
use Symfony\Component\HttpFoundation\JsonResponse;
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
        private readonly ThemePackageService $themePackageService,
        private readonly ThemeFileEditor $themeFileEditor,
    ) {
    }

    #[Route('/aacp/themes', name: 'aacp_themes', methods: ['GET'])]
    #[CpAdminMenu(label: 'aacp.menu.themes', icon: 'heroicons:swatch', panel: 'aacp', priority: 50, capability: 'system.settings.manage')]
    #[IsGranted('system.settings.manage')]
    public function index(Request $request): Response
    {
        $active = $this->themeRegistry->active();

        $html = $this->twig->render('aacp/themes.html.twig', [
            'themes' => $this->themeRegistry->all(),
            'activeDirName' => $active?->dirName,
            'csrf_token' => $this->csrfTokenManager->getToken(self::CSRF_TOKEN_ID)->getValue(),
            'activated' => $request->query->getBoolean('activated'),
            'uploaded' => $request->query->getBoolean('uploaded'),
            'error' => $request->query->get('error'),
            'problems' => $request->query->all('problems'),
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

    #[Route('/aacp/themes/upload', name: 'aacp_theme_upload', methods: ['POST'])]
    #[IsGranted('system.settings.manage')]
    public function upload(Request $request): RedirectResponse
    {
        $this->assertValidCsrf($request);

        $file = $request->files->get('package');
        if ($file === null) {
            return new RedirectResponse('/aacp/themes?error='.urlencode($this->translator->trans('aacp.themes.upload.missing')));
        }

        $result = $this->themePackageService->installFromUpload($file, $request->request->getBoolean('overwrite'));
        if (!$result['success']) {
            $query = ['error' => $result['message']];
            if (($result['problems'] ?? []) !== []) {
                $query['problems'] = $result['problems'];
            }

            return new RedirectResponse('/aacp/themes?'.http_build_query($query));
        }

        $this->cacheRebuildManager->clearSymfonyCache();

        return new RedirectResponse('/aacp/themes?uploaded=1');
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

    #[Route('/aacp/themes/editor', name: 'aacp_theme_editor', methods: ['GET'])]
    #[CpAdminMenu(label: 'aacp.menu.theme_editor', icon: 'heroicons:code-bracket', panel: 'aacp', priority: 72, capability: 'system.settings.manage', parent: 'aacp_themes')]
    #[IsGranted('system.settings.manage')]
    public function editor(Request $request): Response
    {
        $theme = $this->resolveTheme((string) $request->query->get('theme'));
        $files = $theme instanceof ThemeDefinition ? $this->themeFileEditor->listFiles($theme) : [];
        $relative = (string) $request->query->get('file', $files[0]['path'] ?? '');
        $current = null;
        $error = null;

        if ($theme instanceof ThemeDefinition && $relative !== '') {
            try {
                $current = $this->themeFileEditor->read($theme, $relative);
            } catch (\Throwable $e) {
                $error = $e->getMessage();
                $current = null;
            }
        }

        $html = $this->twig->render('aacp/theme_editor.html.twig', [
            'themes' => $this->themeRegistry->all(),
            'theme' => $theme,
            'files' => $files,
            'current' => $current,
            'csrf_token' => $this->csrfTokenManager->getToken(self::CSRF_TOKEN_ID)->getValue(),
            'error' => $error,
        ]);

        return new Response($html);
    }

    #[Route('/aacp/themes/editor/lint', name: 'aacp_theme_editor_lint', methods: ['POST'])]
    #[IsGranted('system.settings.manage')]
    public function lint(Request $request): Response
    {
        $this->assertValidCsrf($request);

        try {
            $theme = $this->requireTheme((string) $request->request->get('theme'));
            $relative = (string) $request->request->get('file');
            $content = (string) $request->request->get('content');
            $kind = $this->themeFileEditor->kindFromRelative($relative) ?? 'twig';
            $this->themeFileEditor->absolutePath($theme, $relative);
            $problems = $this->themeFileEditor->lint($kind, $content, $relative);
        } catch (\Throwable $e) {
            return new JsonResponse(['ok' => false, 'problems' => [$e->getMessage()]], 400);
        }

        return new JsonResponse(['ok' => $problems === [], 'problems' => $problems]);
    }

    #[Route('/aacp/themes/editor/save', name: 'aacp_theme_editor_save', methods: ['POST'])]
    #[IsGranted('system.settings.manage')]
    public function save(Request $request): RedirectResponse
    {
        $this->assertValidCsrf($request);

        $dirName = (string) $request->request->get('theme');
        $relative = (string) $request->request->get('file');
        $content = (string) $request->request->get('content');
        $force = $request->request->getBoolean('force');
        $query = ['theme' => $dirName, 'file' => $relative];

        try {
            $theme = $this->requireTheme($dirName);
            $problems = $this->themeFileEditor->write($theme, $relative, $content, $force);
        } catch (\Throwable $e) {
            return new RedirectResponse('/aacp/themes/editor?'.http_build_query($query + ['error' => $e->getMessage()]));
        }

        if ($problems !== [] && !$force) {
            return new RedirectResponse('/aacp/themes/editor?'.http_build_query($query + [
                'lint' => implode("\n", $problems),
            ]));
        }

        $kind = $this->themeFileEditor->kindFromRelative($relative);
        if ($kind === 'twig') {
            $this->cacheRebuildManager->clearSymfonyCache();
        }

        return new RedirectResponse('/aacp/themes/editor?'.http_build_query($query + ['saved' => '1']));
    }

    private function resolveTheme(string $dirName): ?ThemeDefinition
    {
        if ($dirName !== '' && $this->themeRegistry->has($dirName)) {
            return $this->themeRegistry->get($dirName);
        }

        return $this->themeRegistry->active();
    }

    private function requireTheme(string $dirName): ThemeDefinition
    {
        $theme = $this->resolveTheme($dirName);
        if (!$theme instanceof ThemeDefinition) {
            throw new BadRequestHttpException($this->translator->trans('aacp.themes.editor.no_theme'));
        }

        return $theme;
    }

    private function assertValidCsrf(Request $request): void
    {
        $submitted = (string) $request->request->get('_token');

        if (!$this->csrfTokenManager->isTokenValid(new CsrfToken(self::CSRF_TOKEN_ID, $submitted))) {
            throw new BadRequestHttpException($this->translator->trans('aacp.system.invalid_csrf'));
        }
    }
}
