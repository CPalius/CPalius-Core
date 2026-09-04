<?php

declare(strict_types=1);

namespace Modules\Blog\Controller\Admin;

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

/**
 * Studio screen for blog appearance/hero settings; same cp_settings store as AACP.
 */
#[Route('/admin/blog/settings', name: 'admin_blog_settings_')]
#[IsGranted('blog.category.manage')]
final class BlogSettingsAdminController extends AbstractController
{
    public function __construct(
        private readonly SettingsRegistry $settingsRegistry,
        private readonly SettingRepository $settingRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route('', name: 'index', methods: ['GET'])]
    #[CpAdminMenu(label: 'studio.blog.settings.menu', icon: 'heroicons:paint-brush', panel: 'studio', priority: 23, capability: 'blog.category.manage', parent: 'admin_posts_index')]
    public function index(): Response
    {
        $definitions = $this->blogDefinitions();
        $values = [];
        foreach ($definitions as $definition) {
            $values[$definition->key] = $this->settingsRegistry->get($definition->key);
        }

        $grouped = [];
        foreach ($definitions as $definition) {
            $grouped[$definition->group][] = $definition;
        }

        return $this->render('@BlogModule/admin/settings/index.html.twig', [
            'definitions' => $definitions,
            'grouped' => $grouped,
            'values' => $values,
            'groupLabels' => [
                'blog' => 'studio.blog.settings.group.general',
                'blog_hero' => 'studio.blog.settings.group.hero',
                'blog_showcase' => 'studio.blog.settings.group.showcase',
            ],
        ]);
    }

    #[Route('', name: 'update', methods: ['POST'])]
    public function update(Request $request): Response
    {
        $submittedToken = (string) $request->request->get('_token');
        if (!$this->isCsrfTokenValid('admin_blog_settings', $submittedToken)) {
            throw new BadRequestHttpException($this->translator->trans('studio.blog.settings.csrf_invalid'));
        }

        /** @var array<string, string> $submitted */
        $submitted = $request->request->all('settings');

        $definitions = $this->blogDefinitions();
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
                $this->addFlash('error', $this->translator->trans('studio.blog.settings.invalid_number', [
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
        $this->addFlash('success', $this->translator->trans('studio.blog.settings.saved'));

        return $this->redirectToRoute('admin_blog_settings_index');
    }

    /**
     * @return list<\App\Core\Settings\SettingDefinition>
     */
    private function blogDefinitions(): array
    {
        return array_values(array_filter(
            $this->settingsRegistry->all(),
            static fn ($definition) => $definition->module === 'blog',
        ));
    }
}
