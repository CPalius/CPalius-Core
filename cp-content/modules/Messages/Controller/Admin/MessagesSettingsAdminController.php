<?php

declare(strict_types=1);

namespace Modules\Messages\Controller\Admin;

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

#[Route('/admin/messages/settings', name: 'admin_messages_settings_')]
#[IsGranted('messages.settings.manage')]
final class MessagesSettingsAdminController extends AbstractController
{
    private const CSRF_TOKEN = 'admin_messages_settings';
    private const MODULE = 'messages';

    private const GROUP_LABELS = [
        'messages' => 'messages.settings.group.general',
        'messages_limits' => 'messages.settings.group.limits',
        'messages_privacy' => 'messages.settings.group.privacy',
        'messages_appearance' => 'messages.settings.group.appearance',
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
    #[CpAdminMenu(label: 'messages.menu.settings', icon: 'heroicons:cog-6-tooth', panel: 'studio', priority: 37, capability: 'messages.settings.manage', parent: 'admin_messages_dashboard')]
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
                    $translated[$definition->key][$locale->getCode()] =
                        $this->settingsRegistry->getForLocale($definition->key, $locale->getCode(), '');
                }
            }
        }

        $grouped = [];
        foreach ($definitions as $definition) {
            $grouped[$definition->group][] = $definition;
        }

        $ordered = [];
        foreach (array_keys(self::GROUP_LABELS) as $group) {
            if (isset($grouped[$group])) {
                $ordered[$group] = $grouped[$group];
            }
        }
        foreach ($grouped as $group => $rows) {
            $ordered[$group] ??= $rows;
        }

        return $this->render('@MessagesModule/admin/settings/index.html.twig', [
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
            throw new BadRequestHttpException($this->translator->trans('messages.error.invalid_csrf'));
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
            $this->addFlash('error', $this->translator->trans('messages.settings.invalid_number', ['label' => $label]));
        }

        $this->addFlash('success', $this->translator->trans('messages.settings.flash.saved'));

        return $this->redirectToRoute('admin_messages_settings_index');
    }

    /**
     * @param array<string, mixed> $submitted
     */
    private function resolveSubmitted(SettingDefinition $definition, array $submitted): ?string
    {
        $raw = $submitted[$definition->key] ?? null;

        if ($definition->type === 'checkbox' || $definition->type === 'boolean') {
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
