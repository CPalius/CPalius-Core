<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Core\Annotation\CpAdminMenu;
use App\Core\Portal\PortalLayoutService;
use App\Core\Settings\SettingsRegistry;
use App\Entity\Setting;
use App\Repository\SettingRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Studio "Ana Sayfa": kök route modu + portal blok sürükle-bırak düzeni.
 */
final class HomepageSettingsController extends AbstractController
{
    private const SETTINGS_MODULE = 'studio_homepage';
    private const MODE_KEY = 'homepage.mode';

    public function __construct(
        private readonly SettingsRegistry $settingsRegistry,
        private readonly SettingRepository $settingRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly CsrfTokenManagerInterface $csrfTokenManager,
        private readonly TranslatorInterface $translator,
        private readonly PortalLayoutService $portalLayoutService,
    ) {
    }

    #[Route('/admin/homepage', name: 'admin_homepage_settings', methods: ['GET'])]
    #[CpAdminMenu(label: 'Ana Sayfa', icon: 'heroicons:home-modern', panel: 'studio', priority: 11, group: 'İçerik')]
    #[IsGranted('admin.access')]
    public function index(): Response
    {
        $modeDefinition = $this->settingsRegistry->getDefinition(self::MODE_KEY);

        return $this->render('admin/homepage.html.twig', [
            'modeDefinition' => $modeDefinition,
            'modeValue' => $this->settingsRegistry->get(self::MODE_KEY),
            'portalBlocks' => $this->portalLayoutService->getLayout(),
            'portalCatalog' => $this->portalLayoutService->getCatalog(),
            'csrf_token' => $this->csrfTokenManager->getToken('studio_homepage_settings')->getValue(),
        ]);
    }

    #[Route('/admin/homepage/update', name: 'admin_homepage_settings_update', methods: ['POST'])]
    #[IsGranted('admin.access')]
    public function update(Request $request): RedirectResponse
    {
        $submittedToken = (string) $request->request->get('_token');
        if (!$this->csrfTokenManager->isTokenValid(new CsrfToken('studio_homepage_settings', $submittedToken))) {
            throw new BadRequestHttpException($this->translator->trans('aacp.system.invalid_csrf'));
        }

        $mode = trim((string) $request->request->get('homepage_mode', 'portal'));
        $modeDefinition = $this->settingsRegistry->getDefinition(self::MODE_KEY);
        if ($modeDefinition !== null && $modeDefinition->variants !== []) {
            if (!array_key_exists($mode, $modeDefinition->variants)) {
                $mode = (string) $modeDefinition->default;
            }
        }

        $modeSetting = $this->settingRepository->findOneBy(['settingKey' => self::MODE_KEY]);
        if (!$modeSetting instanceof Setting) {
            $modeSetting = new Setting(self::MODE_KEY, self::SETTINGS_MODULE);
            $this->entityManager->persist($modeSetting);
        }
        $modeSetting->setSettingValue($mode);

        $layoutJson = (string) $request->request->get('portal_layout', '[]');
        /** @var mixed $decoded */
        $decoded = json_decode($layoutJson, true);
        if (!is_array($decoded)) {
            throw new BadRequestHttpException($this->translator->trans('studio.homepage.invalid_layout'));
        }

        /** @var list<array<string, mixed>> $decoded */
        $this->portalLayoutService->saveLayout($decoded);

        $this->addFlash('success', $this->translator->trans('studio.homepage.saved'));

        return $this->redirectToRoute('admin_homepage_settings');
    }
}
