<?php

declare(strict_types=1);

namespace Modules\Forum\Controller\Admin;

use App\Core\Annotation\CpAdminMenu;
use App\Core\Settings\SettingDefinition;
use App\Core\Settings\SettingsRegistry;
use App\Core\Settings\SystemSettingsService;
use App\Entity\Setting;
use App\Repository\SettingRepository;
use Doctrine\ORM\EntityManagerInterface;
use Modules\Forum\Service\ForumCensorService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Studio screen for forum settings; same cp_settings store as AACP module settings.
 * The generic AACP screen stays as the Core Never Dies recovery surface (Law 2.3).
 */
#[Route('/admin/forum/settings', name: 'admin_forum_settings_')]
#[IsGranted('forum.section.manage')]
final class ForumSettingsAdminController extends AbstractController
{
    public function __construct(
        private readonly SettingsRegistry $settingsRegistry,
        private readonly SettingRepository $settingRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly TranslatorInterface $translator,
        private readonly ForumCensorService $censorService,
        private readonly SystemSettingsService $systemSettings,
    ) {
    }

    #[Route('', name: 'index', methods: ['GET'])]
    #[CpAdminMenu(label: 'aacp.menu.forums_settings', icon: 'heroicons:cog-6-tooth', panel: 'studio', priority: 26, capability: 'forum.section.manage', group: 'studio.group.content', parent: 'admin_forum_dashboard')]
    public function index(): Response
    {
        $definitions = array_values(array_filter(
            $this->settingsRegistry->all(),
            static fn ($definition) => $definition->module === 'forum',
        ));

        $homeDefinitions = [];
        $engineDefinitions = [];
        foreach ($definitions as $definition) {
            if ($definition->group === 'forum.home') {
                $homeDefinitions[] = $definition;
            } else {
                $engineDefinitions[] = $definition;
            }
        }

        return $this->render('@ForumModule/admin/settings/index.html.twig', [
            'homeDefinitions' => $homeDefinitions,
            'engineDefinitions' => $engineDefinitions,
            'values' => $this->systemSettings->currentValuesForDefinitions($definitions, $this->settingsRegistry),
            'censorWords' => $this->censorService->all(),
        ]);
    }

    #[Route('', name: 'update', methods: ['POST'])]
    #[IsGranted('forum.section.manage')]
    public function update(Request $request): Response
    {
        $submittedToken = (string) $request->request->get('_token');
        if (!$this->isCsrfTokenValid('admin_forum_settings', $submittedToken)) {
            throw new BadRequestHttpException($this->translator->trans('studio.forum.settings.csrf_invalid'));
        }

        /** @var array<string, mixed> $submitted */
        $submitted = $request->request->all('settings');

        $forumDefinitions = array_values(array_filter(
            $this->settingsRegistry->all(),
            static fn ($definition) => $definition->module === 'forum',
        ));

        $keys = array_map(static fn (SettingDefinition $definition) => $definition->key, $forumDefinitions);
        $existing = $this->settingRepository->findIndexedByKeys($keys);

        foreach ($forumDefinitions as $definition) {
            $raw = $submitted[$definition->key] ?? null;
            if ($definition->isTranslatable()) {
                $value = $this->systemSettings->encodeTranslationMap(\is_array($raw) || \is_string($raw) ? $raw : null);
            } else {
                $value = match ($definition->type) {
                    'checkbox', 'boolean' => $raw !== null ? '1' : '0',
                    default => \is_string($raw) ? trim($raw) : null,
                };
            }

            if ($value === null) {
                continue;
            }

            if ($definition->type === 'integer' && preg_match('/^-?\d+$/', $value) !== 1) {
                $this->addFlash('error', $this->translator->trans('studio.forum.settings.invalid_number', ['label' => $this->translator->trans($definition->label), 'value' => $value]));

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
        $this->addFlash('success', $this->translator->trans('studio.forum.settings.updated'));

        return $this->redirectToRoute('admin_forum_settings_index');
    }

    #[Route('/censor', name: 'censor_add', methods: ['POST'])]
    public function addCensor(Request $request): Response
    {
        if (!$this->isCsrfTokenValid('admin_forum_settings', (string) $request->request->get('_token'))) {
            throw new BadRequestHttpException($this->translator->trans('studio.forum.settings.csrf_invalid'));
        }

        $word = trim((string) $request->request->get('word'));
        if ($word === '') {
            $this->addFlash('error', $this->translator->trans('studio.forum.censor.word_required'));

            return $this->redirectToRoute('admin_forum_settings_index');
        }

        $this->censorService->add($word, trim((string) $request->request->get('replacement')) ?: null);
        $this->addFlash('success', $this->translator->trans('studio.forum.censor.added'));

        return $this->redirectToRoute('admin_forum_settings_index');
    }

    #[Route('/censor/{id}', name: 'censor_delete', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function deleteCensor(int $id, Request $request): Response
    {
        if (!$this->isCsrfTokenValid('admin_forum_settings', (string) $request->request->get('_token'))) {
            throw new BadRequestHttpException($this->translator->trans('studio.forum.settings.csrf_invalid'));
        }

        $word = $this->censorService->all();
        foreach ($word as $row) {
            if ($row->getId() === $id) {
                $this->censorService->remove($row);
                $this->addFlash('success', $this->translator->trans('studio.forum.censor.deleted'));

                return $this->redirectToRoute('admin_forum_settings_index');
            }
        }

        return $this->redirectToRoute('admin_forum_settings_index');
    }
}
