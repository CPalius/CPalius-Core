<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Core\Annotation\CpAdminMenu;
use App\Core\Portal\PortalCopyService;
use App\Core\Portal\PortalLayoutService;
use App\Core\Settings\SettingsRegistry;
use App\Entity\Setting;
use App\Repository\SettingRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
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
use Throwable;

/**
 * Studio homepage: root route mode, portal block layout, and bilingual showcase copy.
 */
final class HomepageSettingsController extends AbstractController
{
    private const SETTINGS_MODULE = 'studio_homepage';
    private const MODE_KEY = 'homepage.mode';
    private const CSRF_ID = 'studio_homepage_settings';

    public function __construct(
        private readonly SettingsRegistry $settingsRegistry,
        private readonly SettingRepository $settingRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly CsrfTokenManagerInterface $csrfTokenManager,
        private readonly TranslatorInterface $translator,
        private readonly PortalLayoutService $portalLayoutService,
        private readonly PortalCopyService $portalCopyService,
    ) {
    }

    #[Route('/admin/homepage', name: 'admin_homepage_settings', methods: ['GET'])]
    #[CpAdminMenu(label: 'studio.homepage.header', icon: 'heroicons:home-modern', panel: 'studio', priority: 11, group: 'studio.group.content')]
    #[IsGranted('admin.access')]
    public function index(): Response
    {
        $modeDefinition = $this->settingsRegistry->getDefinition(self::MODE_KEY);
        $copyFields = [];
        $copyTextareas = [];
        $copyValues = $this->portalCopyService->valuesForAllBlocks();

        foreach (array_keys($copyValues) as $blockId) {
            $keys = $this->portalCopyService->fieldKeys($blockId);
            $copyFields[$blockId] = $keys;
            foreach ($keys as $key) {
                if ($this->portalCopyService->isTextareaField($key)) {
                    $copyTextareas[$blockId][$key] = true;
                }
            }
        }

        return $this->render('admin/homepage.html.twig', [
            'modeDefinition' => $modeDefinition,
            'modeValue' => $this->settingsRegistry->get(self::MODE_KEY),
            'portalBlocks' => $this->portalLayoutService->getLayout(),
            'portalCatalog' => $this->portalLayoutService->getCatalog(),
            'portalLocaleTabs' => $this->portalCopyService->localeTabs(),
            'portalCopyFields' => $copyFields,
            'portalCopyValues' => $copyValues,
            'portalCopyTextareas' => $copyTextareas,
            'csrf_token' => $this->csrfTokenManager->getToken(self::CSRF_ID)->getValue(),
            'save_block_url' => $this->generateUrl('admin_homepage_copy_save_block'),
            'reset_block_url' => $this->generateUrl('admin_homepage_copy_reset_block'),
            'reset_all_url' => $this->generateUrl('admin_homepage_copy_reset_all'),
        ]);
    }

    #[Route('/admin/homepage/update', name: 'admin_homepage_settings_update', methods: ['POST'])]
    #[IsGranted('admin.access')]
    public function update(Request $request): RedirectResponse
    {
        $this->assertCsrf($request);

        $this->persistModeAndLayout($request);

        $copyJson = (string) $request->request->get('portal_copy', '{}');
        /** @var mixed $copyDecoded */
        $copyDecoded = json_decode($copyJson, true);
        if (\is_array($copyDecoded)) {
            try {
                $this->portalCopyService->saveAll($copyDecoded);
            } catch (Throwable $e) {
                throw new BadRequestHttpException(
                    $this->translator->trans('studio.homepage.copy_save_failed', ['error' => $e->getMessage()]),
                );
            }
        }

        $this->entityManager->flush();
        $this->settingsRegistry->clearCache();

        $this->addFlash('success', $this->translator->trans('studio.homepage.saved_all'));

        return $this->redirectToRoute('admin_homepage_settings');
    }

    #[Route('/admin/homepage/copy/save-block', name: 'admin_homepage_copy_save_block', methods: ['POST'])]
    #[IsGranted('admin.access')]
    public function saveBlock(Request $request): JsonResponse
    {
        $this->assertCsrf($request);

        $blockId = trim((string) $request->request->get('block_id', ''));
        if ($blockId === '' || !$this->portalCopyService->supports($blockId)) {
            return $this->jsonError($this->translator->trans('studio.homepage.unknown_block'));
        }

        /** @var mixed $decoded */
        $decoded = json_decode((string) $request->request->get('copy', '{}'), true);
        if (!\is_array($decoded)) {
            return $this->jsonError($this->translator->trans('studio.homepage.copy_save_failed', ['error' => 'invalid JSON']));
        }

        try {
            $this->portalCopyService->saveBlock($blockId, $decoded);
        } catch (Throwable $e) {
            return $this->jsonError($this->translator->trans('studio.homepage.copy_save_failed', ['error' => $e->getMessage()]));
        }

        return new JsonResponse([
            'ok' => true,
            'message' => $this->translator->trans('studio.homepage.block_saved'),
            'values' => $this->portalCopyService->valuesForBlock($blockId),
        ]);
    }

    #[Route('/admin/homepage/copy/reset-block', name: 'admin_homepage_copy_reset_block', methods: ['POST'])]
    #[IsGranted('admin.access')]
    public function resetBlock(Request $request): JsonResponse
    {
        $this->assertCsrf($request);

        $blockId = trim((string) $request->request->get('block_id', ''));
        if ($blockId === '' || !$this->portalCopyService->supports($blockId)) {
            return $this->jsonError($this->translator->trans('studio.homepage.unknown_block'));
        }

        try {
            $values = $this->portalCopyService->resetBlock($blockId);
        } catch (Throwable $e) {
            return $this->jsonError($this->translator->trans('studio.homepage.reset_failed', ['error' => $e->getMessage()]));
        }

        return new JsonResponse([
            'ok' => true,
            'message' => $this->translator->trans('studio.homepage.block_reset'),
            'values' => $values,
        ]);
    }

    #[Route('/admin/homepage/copy/reset-all', name: 'admin_homepage_copy_reset_all', methods: ['POST'])]
    #[IsGranted('admin.access')]
    public function resetAll(Request $request): RedirectResponse
    {
        $this->assertCsrf($request);

        try {
            $this->portalCopyService->resetAll();
        } catch (Throwable $e) {
            throw new BadRequestHttpException(
                $this->translator->trans('studio.homepage.reset_failed', ['error' => $e->getMessage()]),
            );
        }

        $this->addFlash('success', $this->translator->trans('studio.homepage.reset_all_done'));

        return $this->redirectToRoute('admin_homepage_settings');
    }

    private function assertCsrf(Request $request): void
    {
        $submittedToken = (string) $request->request->get('_token');
        if (!$this->csrfTokenManager->isTokenValid(new CsrfToken(self::CSRF_ID, $submittedToken))) {
            throw new BadRequestHttpException($this->translator->trans('aacp.system.invalid_csrf'));
        }
    }

    private function persistModeAndLayout(Request $request): void
    {
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
    }

    private function jsonError(string $message): JsonResponse
    {
        return new JsonResponse(['ok' => false, 'message' => $message], Response::HTTP_BAD_REQUEST);
    }
}
