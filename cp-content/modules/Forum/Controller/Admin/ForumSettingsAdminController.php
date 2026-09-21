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
use Modules\Forum\Attribute\ForumSettingsCard;
use Modules\Forum\DependencyInjection\Compiler\ForumSettingsCardPass;
use Modules\Forum\Navigation\ForumNavigation;
use Modules\Forum\Service\ForumCensorService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Settings hub plus per-shelf forms. Persistence still writes the same cp_settings
 * keys the long form used; only the Studio surface is split into cards.
 */
#[Route('/admin/forum/settings', name: 'admin_forum_settings_')]
#[IsGranted('forum.section.manage')]
final class ForumSettingsAdminController extends AbstractController
{
    /** @var list<string> */
    private const RETURN_ROUTES = [
        'admin_forum_settings_index',
        'admin_forum_settings_home',
        'admin_forum_settings_activity',
        'admin_forum_settings_engine',
        'admin_forum_settings_antibump',
        'admin_forum_settings_member',
        'admin_forum_settings_warnings',
        'admin_forum_settings_reputation',
        'admin_forum_settings_notifications',
        'admin_forum_settings_censor',
    ];

    /**
     * @param list<array{label: string, description: string, icon: string, group: string, priority: int, capability: string, route: string}> $cards
     */
    public function __construct(
        private readonly SettingsRegistry $settingsRegistry,
        private readonly SettingRepository $settingRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly TranslatorInterface $translator,
        private readonly ForumCensorService $censorService,
        private readonly SystemSettingsService $systemSettings,
        #[Autowire(param: ForumSettingsCardPass::CONTAINER_PARAMETER)]
        private readonly array $cards,
    ) {
    }

    #[Route('', name: 'index', methods: ['GET'])]
    #[CpAdminMenu(
        label: 'studio.forum.menu.settings',
        icon: 'heroicons:cog-6-tooth',
        panel: 'studio',
        priority: ForumNavigation::SETTINGS,
        capability: 'forum.section.manage',
        group: 'studio.group.content',
        parent: 'admin_forum_dashboard',
    )]
    public function index(): Response
    {
        $groups = [];
        foreach ($this->resolvedCards() as $card) {
            if (!$this->isGranted($card['capability'])) {
                continue;
            }
            $groups[$card['group']][] = $card;
        }

        return $this->render('@ForumModule/admin/settings/index.html.twig', [
            'groups' => $groups,
        ]);
    }

    #[Route('/home', name: 'home', methods: ['GET'])]
    #[ForumSettingsCard(
        label: 'studio.forum.settings.card.home',
        description: 'studio.forum.settings.card.home_desc',
        icon: 'heroicons:home',
        group: 'studio.forum.settings.hub.board',
        priority: 10,
    )]
    public function home(): Response
    {
        return $this->renderGroup('forum.home', 'studio.forum.settings.card.home', 'admin_forum_settings_home');
    }

    #[Route('/activity', name: 'activity', methods: ['GET'])]
    #[ForumSettingsCard(
        label: 'studio.forum.settings.card.activity',
        description: 'studio.forum.settings.card.activity_desc',
        icon: 'heroicons:bolt',
        group: 'studio.forum.settings.hub.board',
        priority: 20,
    )]
    public function activity(): Response
    {
        return $this->renderGroup('forum.activity', 'studio.forum.settings.card.activity', 'admin_forum_settings_activity');
    }

    #[Route('/engine', name: 'engine', methods: ['GET'])]
    #[ForumSettingsCard(
        label: 'studio.forum.settings.card.engine',
        description: 'studio.forum.settings.card.engine_desc',
        icon: 'heroicons:cog-6-tooth',
        group: 'studio.forum.settings.hub.conversation',
        priority: 30,
    )]
    public function engine(): Response
    {
        return $this->renderGroup(
            'forum.engine',
            'studio.forum.settings.card.engine',
            'admin_forum_settings_engine',
            static fn (SettingDefinition $definition): bool => !str_starts_with($definition->key, 'forum.warning_'),
        );
    }

    #[Route('/antibump', name: 'antibump', methods: ['GET'])]
    #[ForumSettingsCard(
        label: 'studio.forum.settings.card.antibump',
        description: 'studio.forum.settings.card.antibump_desc',
        icon: 'heroicons:no-symbol',
        group: 'studio.forum.settings.hub.conversation',
        priority: 40,
    )]
    public function antibump(): Response
    {
        return $this->renderGroup('forum.antibump', 'studio.forum.settings.card.antibump', 'admin_forum_settings_antibump');
    }

    #[Route('/member', name: 'member', methods: ['GET'])]
    #[ForumSettingsCard(
        label: 'studio.forum.settings.card.member',
        description: 'studio.forum.settings.card.member_desc',
        icon: 'heroicons:user',
        group: 'studio.forum.settings.hub.people',
        priority: 50,
    )]
    public function member(): Response
    {
        return $this->renderGroup('forum.member', 'studio.forum.settings.card.member', 'admin_forum_settings_member');
    }

    #[Route('/warnings', name: 'warnings', methods: ['GET'])]
    #[ForumSettingsCard(
        label: 'studio.forum.settings.card.warnings',
        description: 'studio.forum.settings.card.warnings_desc',
        icon: 'heroicons:exclamation-triangle',
        group: 'studio.forum.settings.hub.people',
        priority: 60,
    )]
    public function warnings(): Response
    {
        return $this->renderGroup(
            'forum.engine',
            'studio.forum.settings.card.warnings',
            'admin_forum_settings_warnings',
            static fn (SettingDefinition $definition): bool => str_starts_with($definition->key, 'forum.warning_'),
        );
    }

    #[Route('/reputation', name: 'reputation', methods: ['GET'])]
    #[ForumSettingsCard(
        label: 'studio.forum.settings.card.reputation',
        description: 'studio.forum.settings.card.reputation_desc',
        icon: 'heroicons:hand-thumb-up',
        group: 'studio.forum.settings.hub.people',
        priority: 70,
    )]
    public function reputation(): Response
    {
        return $this->renderGroup('forum.reputation', 'studio.forum.settings.card.reputation', 'admin_forum_settings_reputation');
    }

    #[Route('/notifications', name: 'notifications', methods: ['GET'])]
    #[ForumSettingsCard(
        label: 'studio.forum.settings.card.notifications',
        description: 'studio.forum.settings.card.notifications_desc',
        icon: 'heroicons:bell',
        group: 'studio.forum.settings.hub.people',
        priority: 80,
    )]
    public function notifications(): Response
    {
        return $this->renderGroup('forum.notifications', 'studio.forum.settings.card.notifications', 'admin_forum_settings_notifications');
    }

    #[Route('/censor', name: 'censor', methods: ['GET'])]
    #[ForumSettingsCard(
        label: 'studio.forum.settings.card.censor',
        description: 'studio.forum.settings.card.censor_desc',
        icon: 'heroicons:eye-slash',
        group: 'studio.forum.settings.hub.maintenance',
        priority: 100,
    )]
    public function censor(): Response
    {
        return $this->render('@ForumModule/admin/settings/censor.html.twig', [
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

        $scopeKeys = array_values(array_filter(
            array_map('strval', (array) $request->request->all('setting_keys')),
            static fn (string $key): bool => $key !== '',
        ));

        $forumDefinitions = $this->forumDefinitions();
        $keys = array_map(static fn (SettingDefinition $definition) => $definition->key, $forumDefinitions);
        $existing = $this->settingRepository->findIndexedByKeys($keys);

        foreach ($forumDefinitions as $definition) {
            if ($scopeKeys !== [] && !\in_array($definition->key, $scopeKeys, true)) {
                continue;
            }

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

        return $this->redirectToRoute($this->resolveReturnRoute($request));
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

            return $this->redirectToRoute('admin_forum_settings_censor');
        }

        $this->censorService->add($word, trim((string) $request->request->get('replacement')) ?: null);
        $this->addFlash('success', $this->translator->trans('studio.forum.censor.added'));

        return $this->redirectToRoute('admin_forum_settings_censor');
    }

    #[Route('/censor/{id}', name: 'censor_delete', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function deleteCensor(int $id, Request $request): Response
    {
        if (!$this->isCsrfTokenValid('admin_forum_settings', (string) $request->request->get('_token'))) {
            throw new BadRequestHttpException($this->translator->trans('studio.forum.settings.csrf_invalid'));
        }

        foreach ($this->censorService->all() as $row) {
            if ($row->getId() === $id) {
                $this->censorService->remove($row);
                $this->addFlash('success', $this->translator->trans('studio.forum.censor.deleted'));

                return $this->redirectToRoute('admin_forum_settings_censor');
            }
        }

        return $this->redirectToRoute('admin_forum_settings_censor');
    }

    /**
     * @param callable(SettingDefinition): bool|null $filter
     */
    private function renderGroup(string $group, string $titleKey, string $returnRoute, ?callable $filter = null): Response
    {
        $definitions = array_values(array_filter(
            $this->forumDefinitions(),
            static function (SettingDefinition $definition) use ($group, $filter): bool {
                if ($definition->group !== $group) {
                    return false;
                }

                return $filter === null || $filter($definition);
            },
        ));

        return $this->render('@ForumModule/admin/settings/group.html.twig', [
            'titleKey' => $titleKey,
            'definitions' => $definitions,
            'values' => $this->systemSettings->currentValuesForDefinitions($definitions, $this->settingsRegistry),
            'returnRoute' => $returnRoute,
        ]);
    }

    /**
     * @return list<array{label: string, description: string, icon: string, group: string, priority: int, capability: string, route: string}>
     */
    private function resolvedCards(): array
    {
        if ($this->cards !== []) {
            return $this->cards;
        }

        return [
            ['label' => 'studio.forum.settings.card.home', 'description' => 'studio.forum.settings.card.home_desc', 'icon' => 'heroicons:home', 'group' => 'studio.forum.settings.hub.board', 'priority' => 10, 'capability' => 'forum.section.manage', 'route' => 'admin_forum_settings_home'],
            ['label' => 'studio.forum.settings.card.activity', 'description' => 'studio.forum.settings.card.activity_desc', 'icon' => 'heroicons:bolt', 'group' => 'studio.forum.settings.hub.board', 'priority' => 20, 'capability' => 'forum.section.manage', 'route' => 'admin_forum_settings_activity'],
            ['label' => 'studio.forum.settings.card.engine', 'description' => 'studio.forum.settings.card.engine_desc', 'icon' => 'heroicons:cog-6-tooth', 'group' => 'studio.forum.settings.hub.conversation', 'priority' => 30, 'capability' => 'forum.section.manage', 'route' => 'admin_forum_settings_engine'],
            ['label' => 'studio.forum.settings.card.antibump', 'description' => 'studio.forum.settings.card.antibump_desc', 'icon' => 'heroicons:no-symbol', 'group' => 'studio.forum.settings.hub.conversation', 'priority' => 40, 'capability' => 'forum.section.manage', 'route' => 'admin_forum_settings_antibump'],
            ['label' => 'studio.forum.settings.card.member', 'description' => 'studio.forum.settings.card.member_desc', 'icon' => 'heroicons:user', 'group' => 'studio.forum.settings.hub.people', 'priority' => 50, 'capability' => 'forum.section.manage', 'route' => 'admin_forum_settings_member'],
            ['label' => 'studio.forum.settings.card.warnings', 'description' => 'studio.forum.settings.card.warnings_desc', 'icon' => 'heroicons:exclamation-triangle', 'group' => 'studio.forum.settings.hub.people', 'priority' => 60, 'capability' => 'forum.section.manage', 'route' => 'admin_forum_settings_warnings'],
            ['label' => 'studio.forum.settings.card.reputation', 'description' => 'studio.forum.settings.card.reputation_desc', 'icon' => 'heroicons:hand-thumb-up', 'group' => 'studio.forum.settings.hub.people', 'priority' => 70, 'capability' => 'forum.section.manage', 'route' => 'admin_forum_settings_reputation'],
            ['label' => 'studio.forum.settings.card.notifications', 'description' => 'studio.forum.settings.card.notifications_desc', 'icon' => 'heroicons:bell', 'group' => 'studio.forum.settings.hub.people', 'priority' => 80, 'capability' => 'forum.section.manage', 'route' => 'admin_forum_settings_notifications'],
            ['label' => 'studio.forum.settings.card.postbit', 'description' => 'studio.forum.settings.card.postbit_desc', 'icon' => 'heroicons:identification', 'group' => 'studio.forum.settings.hub.appearance', 'priority' => 90, 'capability' => 'forum.section.manage', 'route' => 'admin_forum_postbit_index'],
            ['label' => 'studio.forum.settings.card.censor', 'description' => 'studio.forum.settings.card.censor_desc', 'icon' => 'heroicons:eye-slash', 'group' => 'studio.forum.settings.hub.maintenance', 'priority' => 100, 'capability' => 'forum.section.manage', 'route' => 'admin_forum_settings_censor'],
            ['label' => 'studio.forum.settings.card.maintenance', 'description' => 'studio.forum.settings.card.maintenance_desc', 'icon' => 'heroicons:chart-bar', 'group' => 'studio.forum.settings.hub.maintenance', 'priority' => 110, 'capability' => 'forum.section.manage', 'route' => 'admin_forum_stats_index'],
        ];
    }

    /**
     * @return list<SettingDefinition>
     */
    private function forumDefinitions(): array
    {
        return array_values(array_filter(
            $this->settingsRegistry->all(),
            static fn (SettingDefinition $definition): bool => $definition->module === 'forum',
        ));
    }

    private function resolveReturnRoute(Request $request): string
    {
        $return = (string) $request->request->get('return_route', 'admin_forum_settings_index');

        return \in_array($return, self::RETURN_ROUTES, true) ? $return : 'admin_forum_settings_index';
    }
}
