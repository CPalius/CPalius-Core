<?php

declare(strict_types=1);

namespace Modules\Showcase\Controller\Admin;

use App\Core\Annotation\CpAdminMenu;
use App\Core\Localization\LocaleProvider;
use App\Core\Settings\SettingDefinition;
use App\Core\Settings\SettingsRegistry;
use App\Core\Settings\SystemSettingsService;
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
 * Showcase settings inside the module's own desk.
 *
 * The same cp_settings rows the generic /aacp/settings screen edits — this is
 * not a second store, it is a screen that groups the module's own keys where
 * the person configuring the showcase is already standing.
 *
 * Translatable settings (the hero copy) get one input per locale, because a
 * heading stored as a single string would be wrong in every language but one.
 */
#[Route('/admin/showcase/settings', name: 'admin_showcase_settings_')]
#[IsGranted('showcase.type.manage')]
final class ShowcaseSettingsAdminController extends AbstractController
{
    private const CSRF_TOKEN = 'admin_showcase_settings';
    private const MODULE = 'showcase';

    /** Render order; anything not listed falls to the end under its own key. */
    private const GROUP_LABELS = [
        'showcase' => 'showcase.settings.group.general',
        'showcase_appearance' => 'showcase.settings.group.appearance',
        'showcase_reviews' => 'showcase.settings.group.reviews',
        'showcase_security' => 'showcase.settings.group.security',
    ];

    public function __construct(
        private readonly SettingsRegistry $settingsRegistry,
        private readonly SettingRepository $settingRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly SystemSettingsService $systemSettings,
        private readonly LocaleProvider $localeProvider,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route('', name: 'index', methods: ['GET'])]
    #[CpAdminMenu(label: 'showcase.menu.settings', icon: 'heroicons:cog-6-tooth', panel: 'studio', priority: 35, capability: 'showcase.type.manage', parent: 'admin_showcase_dashboard')]
    public function index(): Response
    {
        $definitions = $this->definitions();
        $locales = $this->localeProvider->getLocales();

        $values = [];
        $translated = [];

        foreach ($definitions as $definition) {
            $values[$definition->key] = $this->settingsRegistry->get($definition->key);

            if ($definition->isTranslatable()) {
                foreach ($locales as $locale) {
                    $translated[$definition->key][$locale->code] =
                        $this->settingsRegistry->getForLocale($definition->key, $locale->code, '');
                }
            }
        }

        $grouped = [];
        foreach ($definitions as $definition) {
            $grouped[$definition->group][] = $definition;
        }

        // Keep the declared order, then anything unexpected.
        $ordered = [];
        foreach (array_keys(self::GROUP_LABELS) as $group) {
            if (isset($grouped[$group])) {
                $ordered[$group] = $grouped[$group];
            }
        }
        foreach ($grouped as $group => $rows) {
            $ordered[$group] ??= $rows;
        }

        return $this->render('@ShowcaseModule/admin/settings/index.html.twig', [
            'grouped' => $ordered,
            'groupLabels' => self::GROUP_LABELS,
            'values' => $values,
            'translated' => $translated,
            'locales' => $locales,
            'csrfToken' => self::CSRF_TOKEN,
        ]);
    }

    #[Route('', name: 'update', methods: ['POST'])]
    public function update(Request $request): Response
    {
        if (!$this->isCsrfTokenValid(self::CSRF_TOKEN, (string) $request->request->get('_token'))) {
            throw new BadRequestHttpException($this->translator->trans('showcase.error.invalid_csrf'));
        }

        /** @var array<string, mixed> $submitted */
        $submitted = $request->request->all('settings');

        $definitions = $this->definitions();
        $existing = $this->settingRepository->findIndexedByKeys(
            array_map(static fn (SettingDefinition $d): string => $d->key, $definitions),
        );

        $rejected = [];

        foreach ($definitions as $definition) {
            $value = $this->resolveSubmitted($definition, $submitted);

            if ($value === null) {
                continue;
            }

            if ($definition->type === 'integer' && preg_match('/^-?\d+$/', $value) !== 1) {
                $rejected[] = $this->translator->trans($definition->label);
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

        foreach ($rejected as $label) {
            $this->addFlash('error', $this->translator->trans('showcase.settings.invalid_number', ['label' => $label]));
        }

        $this->addFlash('success', $this->translator->trans('showcase.settings.flash.saved'));

        return $this->redirectToRoute('admin_showcase_settings_index');
    }

    /**
     * Turns one submitted field into the string that goes in cp_settings.
     *
     * Translatable values are encoded by the core service rather than here:
     * the JSON map it writes is the exact shape SettingsRegistry reads back, and
     * a second implementation of that format would be a bug waiting for the day
     * the two drift.
     *
     * @param array<string, mixed> $submitted
     */
    private function resolveSubmitted(SettingDefinition $definition, array $submitted): ?string
    {
        $raw = $submitted[$definition->key] ?? null;

        if ($definition->type === 'checkbox' || $definition->type === 'boolean') {
            // An unchecked box posts nothing, and that absence IS the value.
            // Safe here only because this loop walks showcase definitions alone;
            // running it over every module's settings would silently clear them.
            return $raw !== null ? '1' : '0';
        }

        if ($definition->isTranslatable()) {
            return \is_string($raw) || \is_array($raw)
                ? $this->systemSettings->encodeTranslationMap($raw)
                : null;
        }

        if (\is_array($raw)) {
            return null;
        }

        return $raw !== null ? trim((string) $raw) : null;
    }

    /**
     * @return list<SettingDefinition>
     */
    private function definitions(): array
    {
        return array_values(array_filter(
            $this->settingsRegistry->all(),
            static fn (SettingDefinition $definition): bool => $definition->module === self::MODULE,
        ));
    }
}
