<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Core\Annotation\CpAdminMenu;
use App\Core\Font\FontLibrary;
use App\Core\Theme\StudioAppearance;
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

/** Where an administrator picks the look of Studio for everyone who uses it. */
final class AACPStudioAppearanceController
{
    private const CSRF_TOKEN_ID = 'aacp_studio_appearance';

    public function __construct(
        private readonly Environment $twig,
        private readonly StudioAppearance $appearance,
        private readonly FontLibrary $fonts,
        private readonly CsrfTokenManagerInterface $csrfTokenManager,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route('/aacp/studio-appearance', name: 'aacp_studio_appearance', methods: ['GET'])]
    #[CpAdminMenu(label: 'aacp.menu.studio_appearance', icon: 'heroicons:swatch', panel: 'aacp', priority: 76, capability: 'system.settings.manage', parent: 'aacp_themes')]
    #[IsGranted('system.settings.manage')]
    public function index(Request $request): Response
    {
        return new Response($this->twig->render('aacp/studio_appearance/index.html.twig', [
            'current' => $this->appearance->read(),
            'themes' => StudioAppearance::THEMES,
            'palettes' => StudioAppearance::PALETTES,
            'families' => $this->fonts->families(),
            'modes' => StudioAppearance::MODES,
            'saved' => $request->query->getBoolean('saved'),
            'csrf_token' => $this->csrfTokenManager->getToken(self::CSRF_TOKEN_ID)->getValue(),
        ]));
    }

    #[Route('/aacp/studio-appearance', name: 'aacp_studio_appearance_save', methods: ['POST'])]
    #[IsGranted('system.settings.manage')]
    public function save(Request $request): RedirectResponse
    {
        if (!$this->csrfTokenManager->isTokenValid(new CsrfToken(self::CSRF_TOKEN_ID, (string) $request->request->get('_token')))) {
            throw new BadRequestHttpException($this->translator->trans('aacp.system.invalid_csrf'));
        }

        // StudioAppearance::save() falls back to a safe value for anything it was not offered.
        $this->appearance->save(
            (string) $request->request->get('theme'),
            (string) $request->request->get('mode'),
            $this->accent($request),
            (string) $request->request->get('font'),
        );

        return new RedirectResponse('/aacp/studio-appearance?saved=1');
    }

    /** A preset's hex, the custom picker's hex, or empty for the theme's own accent. */
    private function accent(Request $request): string
    {
        $choice = (string) $request->request->get('accent');

        return $choice === 'custom' ? (string) $request->request->get('accent_custom') : $choice;
    }
}
