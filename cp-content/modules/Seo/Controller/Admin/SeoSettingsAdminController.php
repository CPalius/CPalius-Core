<?php

declare(strict_types=1);

namespace Modules\Seo\Controller\Admin;

use App\Core\Annotation\CpAdminMenu;
use App\Core\Settings\SettingDefinition;
use App\Core\Settings\SettingsRegistry;
use App\Core\Settings\SystemSettingsService;
use App\Entity\Setting;
use App\Repository\SettingRepository;
use Doctrine\ORM\EntityManagerInterface;
use Modules\Seo\Install\SeoSettingsSeeder;
use Modules\Seo\Sitemap\SitemapBuilder;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

#[Route('/admin/seo', name: 'admin_seo_')]
#[IsGranted('seo.manage')]
final class SeoSettingsAdminController extends AbstractController
{
    public function __construct(
        private readonly SettingsRegistry $settingsRegistry,
        private readonly SettingRepository $settingRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly SystemSettingsService $systemSettings,
        private readonly SitemapBuilder $sitemaps,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route('', name: 'index', methods: ['GET'])]
    #[CpAdminMenu(
        label: 'studio.seo.menu.settings',
        icon: 'heroicons:magnifying-glass-circle',
        panel: 'studio',
        priority: 32,
        capability: 'seo.manage',
        group: 'İçerik',
    )]
    public function index(): Response
    {
        if ($this->settingRepository->findOneBy(['settingKey' => 'seo.default_title']) === null) {
            SeoSettingsSeeder::seed($this->entityManager->getConnection());
            $this->settingsRegistry->clearCache();
        }

        $definitions = $this->definitions();
        $grouped = [];
        foreach ($definitions as $definition) {
            $grouped[$definition->group][] = $definition;
        }

        return $this->render('@SeoModule/admin/settings/index.html.twig', [
            'grouped' => $grouped,
            'values' => $this->systemSettings->currentValuesForDefinitions($definitions, $this->settingsRegistry),
            'groupLabels' => [
                'seo_identity' => 'studio.seo.group.identity',
                'seo_social' => 'studio.seo.group.social',
                'seo_verify' => 'studio.seo.group.verify',
                'seo_robots' => 'studio.seo.group.robots',
                'seo_blog' => 'studio.seo.group.blog',
                'seo_forum' => 'studio.seo.group.forum',
                'seo_roadmap' => 'studio.seo.group.roadmap',
                'seo_sitemap' => 'studio.seo.group.sitemap',
            ],
            'sitemapEnabled' => (bool) $this->settingsRegistry->get('seo.sitemap_enabled', '1'),
        ]);
    }

    #[Route('', name: 'update', methods: ['POST'])]
    public function update(Request $request): Response
    {
        if (!$this->isCsrfTokenValid('admin_seo_settings', (string) $request->request->get('_token'))) {
            throw new BadRequestHttpException($this->translator->trans('aacp.common.error.invalid_csrf'));
        }

        /** @var array<string, mixed> $submitted */
        $submitted = $request->request->all('settings');
        $definitions = $this->definitions();
        $keys = array_map(static fn (SettingDefinition $d) => $d->key, $definitions);
        $existing = $this->settingRepository->findIndexedByKeys($keys);

        foreach ($definitions as $definition) {
            $raw = $submitted[$definition->key] ?? null;
            if ($definition->isTranslatable()) {
                $encoded = $this->systemSettings->encodeTranslationMap(\is_array($raw) || \is_string($raw) ? $raw : null);
                if ($encoded === null) {
                    continue;
                }
                $this->persist($existing, $definition, $encoded);
                continue;
            }

            $value = match ($definition->type) {
                'checkbox', 'boolean' => $raw !== null ? '1' : '0',
                default => $raw !== null ? trim((string) $raw) : null,
            };
            if ($value === null) {
                continue;
            }
            if ($definition->type === 'integer' && preg_match('/^-?\d+$/', $value) !== 1) {
                continue;
            }
            if ($definition->type === 'select' && $definition->variants !== [] && !array_key_exists($value, $definition->variants)) {
                continue;
            }
            $this->persist($existing, $definition, $value);
        }

        $this->entityManager->flush();
        $this->settingsRegistry->clearCache();
        $this->sitemaps->clearCache();
        $this->addFlash('success', $this->translator->trans('studio.seo.settings.saved'));

        return $this->redirectToRoute('admin_seo_index');
    }

    #[Route('/rebuild-sitemap', name: 'rebuild_sitemap', methods: ['POST'])]
    public function rebuildSitemap(Request $request): Response
    {
        if (!$this->isCsrfTokenValid('admin_seo_rebuild', (string) $request->request->get('_token'))) {
            throw new BadRequestHttpException($this->translator->trans('aacp.common.error.invalid_csrf'));
        }

        $this->sitemaps->clearCache();
        $this->addFlash('success', $this->translator->trans('studio.seo.sitemap.rebuilt'));

        return $this->redirectToRoute('admin_seo_index');
    }

    /**
     * @param array<string, Setting> $existing
     */
    private function persist(array &$existing, SettingDefinition $definition, string $value): void
    {
        $setting = $existing[$definition->key] ?? null;
        if (!$setting instanceof Setting) {
            $setting = new Setting($definition->key, $definition->module);
            $this->entityManager->persist($setting);
            $existing[$definition->key] = $setting;
        }
        $setting->setSettingValue($value);
    }

    /**
     * @return list<SettingDefinition>
     */
    private function definitions(): array
    {
        return array_values(array_filter(
            $this->settingsRegistry->all(),
            static fn (SettingDefinition $definition) => $definition->module === 'seo',
        ));
    }
}
