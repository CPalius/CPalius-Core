<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Core\Annotation\CpAdminMenu;
use App\Core\EventListener\SiteAccessListener;
use App\Core\Extension\ExtensionCatalogue;
use App\Core\Homepage\HomepageEditorState;
use App\Core\Homepage\HomepageHtmlGuard;
use App\Core\Homepage\HomepageStore;
use App\Core\Portal\HomepageModeCatalog;
use App\Core\Portal\HomepageSimpleContent;
use App\Core\Portal\PortalLayoutService;
use App\Core\Settings\SettingsRegistry;
use App\Entity\Setting;
use App\Repository\SettingRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * AACP → Appearance → Homepage.
 *
 * One screen, three decisions: who may see the site at all (access), what the
 * front door is (a module's page, the theme's sections, or a page the operator
 * wrote), and what that page says (every word, link and element, plus SEO).
 *
 * The browser keeps the whole state; "draft" hands it to the preview frame and
 * "publish" makes it live. Nothing is saved piecemeal, so the live page is never
 * half-edited.
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
        private readonly HomepageModeCatalog $homepageModes,
        private readonly HomepageSimpleContent $simpleContent,
        private readonly HomepageStore $store,
        private readonly HomepageEditorState $editorState,
        private readonly HomepageHtmlGuard $guard,
        private readonly ExtensionCatalogue $extensions,
    ) {
    }

    #[Route('/admin/homepage', name: 'admin_homepage_settings', methods: ['GET'])]
    #[CpAdminMenu(label: 'studio.homepage.header', icon: 'heroicons:home-modern', panel: 'aacp', priority: 70, capability: 'system.settings.manage', parent: 'aacp_themes')]
    #[IsGranted('system.settings.manage')]
    public function index(Request $request): Response
    {
        $modeDefinition = $this->settingsRegistry->getDefinition(self::MODE_KEY);
        $modes = [];
        foreach ($modeDefinition?->variants ?? [] as $value => $label) {
            $owner = $this->homepageModes->available()[$value]['module'] ?? null;
            $dir = $this->homepageModes->available()[$value]['dir'] ?? null;
            $modes[] = ['value' => (string) $value, 'label' => $this->translator->trans((string) $label), 'owner' => $owner, 'shot' => $this->shot('modules', $dir)];
        }

        $state = $this->editorState->build() + [
            'access' => (string) $this->settingsRegistry->get(SiteAccessListener::KEY, SiteAccessListener::OPEN),
            'mode' => (string) $this->settingsRegistry->get(self::MODE_KEY),
            'modes' => $modes,
            'dormantModes' => array_values(array_map(
                fn (array $m): array => ['label' => $this->translator->trans($m['label']), 'module' => $m['module']],
                $this->homepageModes->dormant(),
            )),
            // The default theme's own picture stands for "ready-made sections".
            'blocksShot' => $this->shot('themes', 'cpalius-website'),
            'canScript' => $this->isGranted('system.homepage.script'),
            'homeUrl' => $this->generateUrl('site_home', ['_locale' => $request->getLocale()]),
        ];

        return $this->render('admin/homepage.html.twig', [
            // HEX_* so no value can close the <script type="application/json"> block it is printed in.
            'state_json' => json_encode($state, \JSON_HEX_TAG | \JSON_HEX_AMP | \JSON_HEX_APOS | \JSON_HEX_QUOT | \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR),
            'csrf_token' => $this->csrfTokenManager->getToken(self::CSRF_ID)->getValue(),
            'draft_url' => $this->generateUrl('admin_homepage_draft'),
            'publish_url' => $this->generateUrl('admin_homepage_publish'),
        ]);
    }

    /**
     * The preview frame's source. Also runs the html guard, so the editor can show
     * why a script was refused while the author is still typing it.
     */
    #[Route('/admin/homepage/draft', name: 'admin_homepage_draft', methods: ['POST'])]
    #[IsGranted('system.settings.manage')]
    public function draft(Request $request): JsonResponse
    {
        $input = $this->input($request);
        if ($input === null) {
            return $this->fail('invalid');
        }

        $report = $this->guard->check($this->htmlParts($input));
        if (($input['engine'] ?? '') === 'html') {
            $input['html'] = ['html' => $report['html'], 'css' => $report['css'], 'js' => $report['errors'] === [] ? $report['js'] : ''];
        }
        $this->store->saveDraft($this->editorId(), $input);

        return new JsonResponse(['ok' => true, 'errors' => $report['errors'], 'notes' => $report['notes']]);
    }

    #[Route('/admin/homepage/publish', name: 'admin_homepage_publish', methods: ['POST'])]
    #[IsGranted('system.settings.manage')]
    public function publish(Request $request): JsonResponse
    {
        $input = $this->input($request);
        if ($input === null) {
            return $this->fail('invalid');
        }

        // Writing a page of HTML/CSS/JS is its own privilege. Without it the
        // submitted page and engine are ignored in favour of what is stored, so a
        // settings manager can still reorder sections but cannot introduce script.
        $engine = (string) ($input['engine'] ?? 'blocks');
        if (!$this->isGranted('system.homepage.script')) {
            $stored = $this->store->doc();
            $input['html'] = $stored['html'];
            $engine = $input['engine'] = $stored['engine'];
        }

        if ($engine === 'html') {
            $report = $this->guard->check($this->htmlParts($input));
            if ($report['errors'] !== []) {
                return new JsonResponse(['ok' => false, 'errors' => $report['errors'], 'message' => $this->translator->trans('studio.hp.publish_refused')], Response::HTTP_UNPROCESSABLE_ENTITY);
            }
            $input['html'] = ['html' => $report['html'], 'css' => $report['css'], 'js' => $report['js']];
        }

        $this->upsert(SiteAccessListener::KEY, $this->oneOf($input['access'] ?? null, SiteAccessListener::MODES, SiteAccessListener::OPEN));

        $modeDefinition = $this->settingsRegistry->getDefinition(self::MODE_KEY);
        $variants = array_keys($modeDefinition?->variants ?? []);
        $this->upsert(self::MODE_KEY, $this->oneOf($input['mode'] ?? null, array_map('strval', $variants), (string) ($modeDefinition?->default ?? 'portal')));

        if (\is_array($input['layout'] ?? null)) {
            $this->portalLayoutService->saveLayout($input['layout']);
        }
        $this->simpleContent->save(
            (string) ($input['template'] ?? 'showcase'),
            \is_array($input['simple'] ?? null) ? $input['simple'] : [],
            (string) ($input['simple_image'] ?? ''),
        );
        $this->store->save($input);
        $this->entityManager->flush();
        $this->settingsRegistry->clearCache();

        return new JsonResponse(['ok' => true, 'message' => $this->translator->trans('studio.hp.published')]);
    }

    /** URL of an installed extension's screenshot, or null when it ships none. */
    private function shot(string $type, ?string $name): ?string
    {
        if ($name === null || $this->extensions->screenshotPath($type, $name) === null) {
            return null;
        }

        return $this->generateUrl('aacp_store_screenshot', ['type' => $type, 'name' => $name]);
    }

    /** @return array<string, mixed>|null */
    private function input(Request $request): ?array
    {
        $submitted = (string) $request->headers->get('X-CSRF-Token', '');
        if (!$this->csrfTokenManager->isTokenValid(new CsrfToken(self::CSRF_ID, $submitted))) {
            return null;
        }
        $decoded = json_decode($request->getContent(), true);

        return \is_array($decoded) ? $decoded : null;
    }

    /**
     * @param array<string, mixed> $input
     *
     * @return array{html: string, css: string, js: string}
     */
    private function htmlParts(array $input): array
    {
        $html = \is_array($input['html'] ?? null) ? $input['html'] : [];

        return [
            'html' => (string) ($html['html'] ?? ''),
            'css' => (string) ($html['css'] ?? ''),
            'js' => (string) ($html['js'] ?? ''),
        ];
    }

    private function editorId(): string
    {
        return (string) $this->getUser()?->getUserIdentifier();
    }

    /** @param list<string> $allowed */
    private function oneOf(mixed $value, array $allowed, string $fallback): string
    {
        return \is_string($value) && \in_array($value, $allowed, true) ? $value : $fallback;
    }

    private function upsert(string $key, string $value): void
    {
        $setting = $this->settingRepository->findOneBy(['settingKey' => $key]);
        if (!$setting instanceof Setting) {
            $setting = new Setting($key, self::SETTINGS_MODULE);
            $this->entityManager->persist($setting);
        }
        $setting->setSettingValue($value);
    }

    private function fail(string $reason): JsonResponse
    {
        return new JsonResponse(['ok' => false, 'message' => $this->translator->trans('aacp.system.invalid_csrf'), 'reason' => $reason], Response::HTTP_BAD_REQUEST);
    }
}
