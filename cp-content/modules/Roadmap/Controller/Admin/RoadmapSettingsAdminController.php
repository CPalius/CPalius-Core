<?php

declare(strict_types=1);

namespace Modules\Roadmap\Controller\Admin;

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

#[Route('/admin/roadmap/settings', name: 'admin_roadmap_settings_')]
#[IsGranted('roadmap.manage')]
final class RoadmapSettingsAdminController extends AbstractController
{
    public function __construct(
        private readonly SettingsRegistry $settingsRegistry,
        private readonly SettingRepository $settingRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route('', name: 'index', methods: ['GET'])]
    #[CpAdminMenu(
        label: 'studio.roadmap.menu.settings',
        icon: 'heroicons:cog-6-tooth',
        panel: 'studio',
        priority: 25,
        capability: 'roadmap.manage',
        parent: 'admin_roadmap_index',
    )]
    public function index(): Response
    {
        $definitions = $this->roadmapDefinitions();
        $values = [];
        foreach ($definitions as $definition) {
            $values[$definition->key] = $this->settingsRegistry->get($definition->key);
        }

        $grouped = [];
        foreach ($definitions as $definition) {
            $grouped[$definition->group][] = $definition;
        }

        return $this->render('@RoadmapModule/admin/settings/index.html.twig', [
            'definitions' => $definitions,
            'grouped' => $grouped,
            'values' => $values,
            'groupLabels' => [
                'roadmap_sources' => 'studio.roadmap.settings.group.sources',
                'roadmap_display' => 'studio.roadmap.settings.group.display',
                'roadmap_portal' => 'studio.roadmap.settings.group.portal',
            ],
        ]);
    }

    #[Route('', name: 'update', methods: ['POST'])]
    public function update(Request $request): Response
    {
        $submittedToken = (string) $request->request->get('_token');
        if (!$this->isCsrfTokenValid('admin_roadmap_settings', $submittedToken)) {
            throw new BadRequestHttpException($this->translator->trans('aacp.common.error.invalid_csrf'));
        }

        /** @var array<string, string> $submitted */
        $submitted = $request->request->all('settings');

        $definitions = $this->roadmapDefinitions();
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
                $this->addFlash('error', $this->translator->trans('studio.roadmap.settings.invalid_number', [
                    'label' => $definition->label,
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
        $this->addFlash('success', $this->translator->trans('studio.roadmap.settings.saved'));

        return $this->redirectToRoute('admin_roadmap_settings_index');
    }

    /**
     * @return list<\App\Core\Settings\SettingDefinition>
     */
    private function roadmapDefinitions(): array
    {
        return array_values(array_filter(
            $this->settingsRegistry->all(),
            static fn ($definition) => $definition->module === 'roadmap',
        ));
    }
}
