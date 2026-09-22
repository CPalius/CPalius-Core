<?php

declare(strict_types=1);

namespace Modules\Ai\Controller\Admin;

use App\Core\Annotation\CpAdminMenu;
use App\Core\Settings\SettingDefinition;
use App\Core\Settings\SettingSecretCodec;
use App\Core\Settings\SettingsRegistry;
use App\Core\Settings\SystemSettingsService;
use App\Entity\Setting;
use App\Repository\SettingRepository;
use Doctrine\ORM\EntityManagerInterface;
use Modules\Ai\Attribute\AiSettingsCard;
use Modules\Ai\Install\AiSettingsSeeder;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

#[Route('/admin/ai', name: 'admin_ai_')]
#[IsGranted('ai.manage')]
final class AiSettingsAdminController extends AbstractController
{
    public function __construct(
        private readonly SettingsRegistry $settingsRegistry,
        private readonly SettingRepository $settingRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly SystemSettingsService $systemSettings,
        private readonly SettingSecretCodec $secretCodec,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route('', name: 'index', methods: ['GET'])]
    #[CpAdminMenu(
        label: 'studio.ai.menu.settings',
        icon: 'heroicons:sparkles',
        panel: 'studio',
        priority: 34,
        capability: 'ai.manage',
        group: 'studio.group.content',
    )]
    #[AiSettingsCard(
        label: 'studio.ai.card.provider',
        description: 'studio.ai.card.provider_help',
        icon: 'heroicons:sparkles',
    )]
    public function index(): Response
    {
        $connection = $this->entityManager->getConnection();
        AiSettingsSeeder::seed($connection);
        if (AiSettingsSeeder::remapLegacyModel($connection)) {
            $this->settingsRegistry->clearCache();
        }

        $definitions = $this->definitions();

        return $this->render('@AiModule/admin/settings/index.html.twig', [
            'definitions' => $definitions,
            'values' => $this->systemSettings->currentValuesForDefinitions($definitions, $this->settingsRegistry),
        ]);
    }

    #[Route('', name: 'update', methods: ['POST'])]
    public function update(Request $request): Response
    {
        if (!$this->isCsrfTokenValid('admin_ai_settings', (string) $request->request->get('_token'))) {
            throw new BadRequestHttpException($this->translator->trans('aacp.common.error.invalid_csrf'));
        }

        /** @var array<string, mixed> $submitted */
        $submitted = $request->request->all('settings');
        $model = isset($submitted['ai.model']) ? strtolower(trim((string) $submitted['ai.model'])) : '';
        if (str_starts_with($model, 'llama-') || str_starts_with($model, 'mixtral-')) {
            $submitted['ai.provider'] = 'groq';
        } elseif (str_starts_with($model, 'gemini-')) {
            $submitted['ai.provider'] = 'gemini';
        }
        $definitions = $this->definitions();
        $keys = array_map(static fn (SettingDefinition $d) => $d->key, $definitions);
        $existing = $this->settingRepository->findIndexedByKeys($keys);

        foreach ($definitions as $definition) {
            $raw = $submitted[$definition->key] ?? null;
            if ($definition->type === 'password') {
                $value = $raw !== null ? trim((string) $raw) : '';
                if ($value === '') {
                    continue;
                }
                $this->persist($existing, $definition, $this->secretCodec->seal($value));
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
        $this->addFlash('success', $this->translator->trans('studio.ai.settings.saved'));

        return $this->redirectToRoute('admin_ai_index');
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
            static fn (SettingDefinition $definition) => $definition->module === 'ai',
        ));
    }
}
