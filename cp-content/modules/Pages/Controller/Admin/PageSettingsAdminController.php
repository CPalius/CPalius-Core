<?php

declare(strict_types=1);

namespace Modules\Pages\Controller\Admin;

use App\Core\Annotation\CpAdminMenu;
use App\Core\Settings\SettingsRegistry;
use App\Entity\Setting;
use App\Repository\SettingRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

#[Route('/admin/pages/settings', name: 'admin_pages_settings_')]
#[IsGranted('pages.settings.manage')]
final class PageSettingsAdminController extends AbstractController
{
    public function __construct(
        private readonly SettingsRegistry $settingsRegistry,
        private readonly SettingRepository $settingRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route('', name: 'index', methods: ['GET'])]
    #[CpAdminMenu(label: 'studio.pages.settings.menu', icon: 'heroicons:cog-6-tooth', panel: 'studio', priority: 15, capability: 'pages.settings.manage', parent: 'admin_pages_index')]
    public function index(): Response
    {
        $definitions = $this->pageDefinitions();
        $values = [];
        foreach ($definitions as $definition) {
            $values[$definition->key] = $this->settingsRegistry->get($definition->key);
        }

        $grouped = [];
        foreach ($definitions as $definition) {
            $grouped[$definition->group][] = $definition;
        }

        return $this->render('@PagesModule/admin/settings/index.html.twig', [
            'definitions' => $definitions,
            'grouped' => $grouped,
            'values' => $values,
            'groupLabels' => [
                'pages' => 'studio.pages.settings.group.general',
            ],
        ]);
    }

    #[Route('', name: 'update', methods: ['POST'])]
    public function update(Request $request): Response
    {
        $submittedToken = (string) $request->request->get('_token');
        if (!$this->isCsrfTokenValid('admin_pages_settings', $submittedToken)) {
            throw new BadRequestHttpException($this->translator->trans('studio.pages.settings.csrf_invalid'));
        }

        /** @var array<string, string> $submitted */
        $submitted = $request->request->all('settings');
        $definitions = $this->pageDefinitions();
        $keys = array_map(static fn ($definition) => $definition->key, $definitions);
        $existing = $this->settingRepository->findIndexedByKeys($keys);

        foreach ($definitions as $definition) {
            $raw = $submitted[$definition->key] ?? null;
            $value = match ($definition->type) {
                'checkbox', 'boolean' => $raw !== null ? '1' : '0',
                default => $raw !== null ? trim((string) $raw) : null,
            };

            if ($value === null) {
                continue;
            }

            if ($definition->type === 'integer' && preg_match('/^-?\d+$/', $value) !== 1) {
                $this->addFlash('error', $this->translator->trans('studio.pages.settings.invalid_number', [
                    'label' => $this->translator->trans($definition->label),
                    'value' => $value,
                ]));

                continue;
            }

            $setting = $existing[$definition->key] ?? null;
            if (!$setting instanceof Setting) {
                $setting = new Setting($definition->key, $definition->module);
                $this->entityManager->persist($setting);
                $existing[$definition->key] = $setting;
            }

            $setting->setSettingValue($value);
        }

        $this->entityManager->flush();
        $this->settingsRegistry->clearCache();
        $this->addFlash('success', $this->translator->trans('studio.pages.settings.saved'));

        return $this->redirectToRoute('admin_pages_settings_index');
    }

    /**
     * @return list<\App\Core\Settings\SettingDefinition>
     */
    private function pageDefinitions(): array
    {
        return array_values(array_filter(
            $this->settingsRegistry->all(),
            static fn ($definition) => $definition->module === 'pages',
        ));
    }
}
