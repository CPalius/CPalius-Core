<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Core\Annotation\CpAdminMenu;
use App\Core\Font\FontFamilyVariantProvider;
use App\Core\Font\FontInstallException;
use App\Core\Font\FontInstaller;
use App\Core\Font\FontLibrary;
use App\Core\Font\FontRole;
use App\Core\Font\FontStylesheetBuilder;
use App\Core\Settings\SettingsRegistry;
use App\Entity\Setting;
use App\Repository\SettingRepository;
use Doctrine\ORM\EntityManagerInterface;
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
 * The font library and the role assignments.
 *
 * Deliberately its own screen rather than rows on System Settings: installing a
 * font is a file operation with a progress-and-failure story, and the choice of
 * which family goes where only makes sense next to the list of what is
 * installed. Saving here also has to regenerate the stylesheet, which the
 * generic settings form has no reason to know about.
 */
final class AACPFontController
{
    private const CSRF_TOKEN_ID = 'aacp_fonts';

    public function __construct(
        private readonly Environment $twig,
        private readonly FontLibrary $library,
        private readonly FontInstaller $installer,
        private readonly FontStylesheetBuilder $stylesheet,
        private readonly FontFamilyVariantProvider $variants,
        private readonly SettingsRegistry $settingsRegistry,
        private readonly SettingRepository $settingRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly CsrfTokenManagerInterface $csrfTokenManager,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route('/aacp/fonts', name: 'aacp_fonts', methods: ['GET'])]
    #[CpAdminMenu(label: 'aacp.menu.fonts', icon: 'heroicons:language', panel: 'aacp', priority: 73, capability: 'system.settings.manage', parent: 'aacp_themes')]
    #[IsGranted('system.settings.manage')]
    public function index(Request $request): Response
    {
        $roles = [];

        foreach (FontRole::all() as $role) {
            $key = FontRole::settingKey($role);
            $roles[] = [
                'role' => $role,
                'key' => $key,
                'label' => 'aacp.fonts.role.'.$role,
                'hint' => 'aacp.fonts.role.'.$role.'_hint',
                'value' => (string) $this->settingsRegistry->get($key, ''),
                'options' => $this->variants->variants($key),
            ];
        }

        return new Response($this->twig->render('aacp/fonts/index.html.twig', [
            'families' => $this->library->families(),
            'roles' => $roles,
            'stylesheet' => $this->stylesheet->exists() ? $this->stylesheet->publicPath() : null,
            'fontsDir' => $this->library->rootPath(),
            'writable' => is_writable(\dirname($this->library->rootPath())),
            'csrf_token' => $this->csrfTokenManager->getToken(self::CSRF_TOKEN_ID)->getValue(),
            'installed' => $request->query->get('installed'),
            'error' => $request->query->get('error'),
            'saved' => $request->query->getBoolean('saved'),
            'removed' => $request->query->get('removed'),
        ]));
    }

    #[Route('/aacp/fonts/install', name: 'aacp_fonts_install', methods: ['POST'])]
    #[IsGranted('system.settings.manage')]
    public function install(Request $request): RedirectResponse
    {
        $this->assertCsrf($request);

        $url = trim((string) $request->request->get('url'));
        $name = trim((string) $request->request->get('name'));

        if ($url === '') {
            return $this->back('error=aacp.fonts.error.url_required');
        }

        try {
            $family = $this->installer->installFromUrl($url, $name !== '' ? $name : null);
        } catch (FontInstallException $e) {
            return $this->back('error='.urlencode($e->getMessage()));
        } catch (\Throwable) {
            return $this->back('error=aacp.fonts.error.download_failed');
        }

        $this->stylesheet->rebuild();

        return $this->back('installed='.urlencode($family->name));
    }

    #[Route('/aacp/fonts/{slug}/delete', name: 'aacp_fonts_delete', methods: ['POST'], requirements: ['slug' => '[a-z0-9-]+'])]
    #[IsGranted('system.settings.manage')]
    public function delete(string $slug, Request $request): RedirectResponse
    {
        $this->assertCsrf($request);

        $family = $this->library->read($slug);
        if ($family === null) {
            return $this->back('error=aacp.fonts.error.not_found');
        }

        // A role still pointing at a deleted family would generate a stack whose
        // first entry does not exist; clearing the assignment is the honest
        // outcome and puts the theme default back.
        foreach (FontRole::all() as $role) {
            if ((string) $this->settingsRegistry->get(FontRole::settingKey($role), '') === $slug) {
                $this->writeSetting(FontRole::settingKey($role), '');
            }
        }

        $this->entityManager->flush();
        $this->settingsRegistry->clearCache(...array_map(FontRole::settingKey(...), FontRole::all()));

        $this->library->delete($slug);
        $this->stylesheet->rebuild();

        return $this->back('removed='.urlencode($family->name));
    }

    #[Route('/aacp/fonts/assign', name: 'aacp_fonts_assign', methods: ['POST'])]
    #[IsGranted('system.settings.manage')]
    public function assign(Request $request): RedirectResponse
    {
        $this->assertCsrf($request);

        /** @var array<string, string> $submitted */
        $submitted = $request->request->all('fonts');
        $keys = [];

        foreach (FontRole::all() as $role) {
            $key = FontRole::settingKey($role);
            $value = trim((string) ($submitted[$role] ?? ''));

            // Only a value the dropdown actually offered: this ends up inside a
            // generated stylesheet, so an arbitrary string would be a CSS
            // injection into every page of the site.
            if ($value !== '' && !\array_key_exists($value, $this->variants->variants($key))) {
                return $this->back('error=aacp.fonts.error.unknown_family');
            }

            $this->writeSetting($key, $value);
            $keys[] = $key;
        }

        $this->entityManager->flush();
        $this->settingsRegistry->clearCache(...$keys);
        $this->stylesheet->rebuild();

        return $this->back('saved=1');
    }

    #[Route('/aacp/fonts/rebuild', name: 'aacp_fonts_rebuild', methods: ['POST'])]
    #[IsGranted('system.settings.manage')]
    public function rebuild(Request $request): RedirectResponse
    {
        $this->assertCsrf($request);
        $this->stylesheet->rebuild();

        return $this->back('saved=1');
    }

    private function writeSetting(string $key, string $value): void
    {
        $setting = $this->settingRepository->findIndexedByKeys([$key])[$key] ?? null;

        if (!$setting instanceof Setting) {
            $setting = new Setting($key, 'core');
            $this->entityManager->persist($setting);
        }

        $setting->setSettingValue($value);
    }

    private function back(string $query): RedirectResponse
    {
        return new RedirectResponse('/aacp/fonts?'.$query);
    }

    private function assertCsrf(Request $request): void
    {
        $submitted = (string) $request->request->get('_token');

        if (!$this->csrfTokenManager->isTokenValid(new CsrfToken(self::CSRF_TOKEN_ID, $submitted))) {
            throw new BadRequestHttpException($this->translator->trans('aacp.system.invalid_csrf'));
        }
    }
}
