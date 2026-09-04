<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Core\Annotation\CpAdminMenu;
use App\Core\Mail\CpMailerService;
use App\Core\Settings\SettingsRegistry;
use App\Core\Settings\SystemSettingsService;
use App\Repository\LocaleRepository;
use App\Repository\SettingRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

/**
 * Faz 5: AACP menü hiyerarşisinde (KULLANICI, GÖRÜNÜM, ADVANCED SET
 * grupları) kullanıcının istediği yeri şimdiden ayırmak için oluşturulan
 * GEÇİCİ uçlar. Her action, ileride gerçek bir modül/özellik olarak
 * (Kullanıcı Yönetimi, Tema Sistemi, Symfony Bundle Yönetimi vb.)
 * doldurulana kadar tek bir ortak "yakında" şablonunu (aacp/placeholder.html.twig)
 * render eder — route adları ve sidebar linkleri KALICIDIR, sadece bu
 * action'ların GÖVDESİ ileride gerçek içerikle değiştirilecektir.
 *
 * Capability olarak henüz ince taneli bir yetenek (ör. "user.manage",
 * "theme.manage") TANIMLANMAMIŞTIR (capabilities.yaml'da yok) — bu yüzden
 * bilinçli olarak mevcut "system.aacp.access" ile korunur; ilgili özellik
 * gerçek içerikle doldurulduğunda kendi capability'sine geçirilmelidir.
 */
final class AACPPlaceholderController
{
    public function __construct(
        private readonly Environment $twig,
        private readonly SettingsRegistry $settingsRegistry,
        private readonly SystemSettingsService $systemSettingsService,
        private readonly CpMailerService $mailerService,
        private readonly SettingRepository $settingRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly CsrfTokenManagerInterface $csrfTokenManager,
        private readonly LocaleRepository $localeRepository,
        private readonly UserRepository $userRepository,
        private readonly TranslatorInterface $translator,
    ) {
    }

    /**
     * "Yöneticiler": 'admin' rol id'sine sahip kullanıcıların salt-okunur
     * listesi (bkz. UserRepository::findByRole()). Bilinçli olarak
     * create/edit/delete İÇERMEZ — bu işlemler zaten tam yetkiyle
     * AACPUserController'daki genel "Kullanıcılar" ekranında yapılır; burası
     * sadece "kimler yönetici" sorusuna hızlı bir cevaptır.
     */
    #[Route('/aacp/administrators', name: 'aacp_administrators', methods: ['GET'])]
    #[CpAdminMenu(label: 'aacp.menu.administrators', icon: 'heroicons:shield-check', panel: 'aacp', priority: 61, capability: 'system.aacp.access', group: 'aacp.group.user')]
    #[IsGranted('system.aacp.access')]
    public function administrators(): Response
    {
        $html = $this->twig->render('aacp/administrators.html.twig', [
            'administrators' => $this->userRepository->findByRole('admin'),
        ]);

        return new Response($html);
    }

    #[Route('/aacp/isolation', name: 'aacp_isolation', methods: ['GET'])]
    #[CpAdminMenu(label: 'aacp.menu.isolation', icon: 'heroicons:lock-closed', panel: 'aacp', priority: 62, capability: 'system.aacp.access', group: 'aacp.group.user')]
    #[IsGranted('system.aacp.access')]
    public function isolation(): Response
    {
        return $this->renderPlaceholder('İzolasyon', 'Kullanıcı ve rol bazlı yetki (permission) seçeneklerinin yönetildiği ekran.');
    }

    #[Route('/aacp/themes/editor', name: 'aacp_theme_editor', methods: ['GET'])]
    #[CpAdminMenu(label: 'aacp.menu.theme_editor', icon: 'heroicons:code-bracket', panel: 'aacp', priority: 72, capability: 'system.aacp.access', parent: 'aacp_themes')]
    #[IsGranted('system.aacp.access')]
    public function themeEditor(): Response
    {
        return $this->renderPlaceholder('Tema Editörü', 'Tema dosyalarına (WordPress tema editörü benzeri) doğrudan dosya sisteminden manuel müdahale edilebilecek ekran.');
    }

    /**
     * "Yönetim": CPalius çekirdeğinin (module: 'core') genel sistem
     * ayarlarını (site adı, zaman dilimi, permalink yapısı vb. — bkz.
     * CoreSettings) VE aktif dil listesini (bkz. App\Entity\Locale) TEK
     * bir sayfada birleştirir. Bilinçli olarak alt menüsü YOKTUR — "Diller"
     * ve eski "Genel Ayarlar" ayrı menü öğeleri DEĞİL, bu sayfanın içinde
     * art arda gelen iki bölümdür (kullanıcı tercihi: WordPress'in Genel
     * Ayarlar ekranına benzer tek-sayfa deneyimi).
     *
     * "Modül Ayarları" (AACPController::settingsModules()) ve "Eklenti
     * Ayarları" BİLİNÇLİ OLARAK burada GÖSTERİLMEZ — bu sayfa sadece
     * çekirdek (module==='core') ayarlarını kapsar, modül/eklenti ayarları
     * kendi ekranlarında (Modüller/Eklentiler alt menüleri) kalmaya devam
     * eder.
     */
    #[Route('/aacp/advanced/management', name: 'aacp_advanced_management', methods: ['GET'])]
    #[CpAdminMenu(label: 'aacp.menu.system_management', icon: 'heroicons:wrench-screwdriver', panel: 'aacp', priority: 80, capability: 'system.aacp.access', group: 'aacp.group.genadset')]
    #[IsGranted('system.aacp.access')]
    public function advancedManagement(Request $request): Response
    {
        $activeTab = (string) $request->query->get('tab', 'general');
        if (!array_key_exists($activeTab, $this->systemSettingsService->tabs())) {
            $activeTab = 'general';
        }

        $tabDefinitions = [];
        $tabValues = [];
        foreach ($this->systemSettingsService->tabs() as $tabId => $tabMeta) {
            if (($tabMeta['isLocales'] ?? false) === true) {
                continue;
            }
            $definitions = $this->systemSettingsService->definitionsForTab($tabId, $this->settingsRegistry);
            $tabDefinitions[$tabId] = $definitions;
            $tabValues[$tabId] = $this->systemSettingsService->currentValuesForDefinitions($definitions, $this->settingsRegistry);
        }

        $html = $this->twig->render('aacp/management.html.twig', [
            'tabs' => $this->systemSettingsService->tabs(),
            'activeTab' => $activeTab,
            'tabDefinitions' => $tabDefinitions,
            'tabValues' => $tabValues,
            'locales' => $this->localeRepository->findBy([], ['sortOrder' => 'ASC']),
            'csrf_token' => $this->csrfTokenManager->getToken(SystemSettingsService::CSRF_TOKEN_ID)->getValue(),
            'locales_csrf_token' => $this->csrfTokenManager->getToken('aacp_locales')->getValue(),
            'mailConfigured' => $this->mailerService->canSend(),
        ]);

        return new Response($html);
    }

    #[Route('/aacp/advanced/management/update', name: 'aacp_advanced_management_update', methods: ['POST'])]
    #[IsGranted('system.aacp.access')]
    public function updateCoreSettings(Request $request): RedirectResponse
    {
        $submittedToken = (string) $request->request->get('_token');
        if (!$this->csrfTokenManager->isTokenValid(new CsrfToken(SystemSettingsService::CSRF_TOKEN_ID, $submittedToken))) {
            throw new BadRequestHttpException($this->translator->trans('aacp.system.invalid_csrf'));
        }

        $tab = (string) $request->request->get('_tab', 'general');
        if (!array_key_exists($tab, $this->systemSettingsService->tabs())) {
            $tab = 'general';
        }

        /** @var array<string, string|null> $submitted */
        $submitted = $request->request->all('settings');

        $invalidKey = $this->systemSettingsService->updateTab(
            $tab,
            $submitted,
            $this->settingsRegistry,
            $this->settingRepository,
            $this->entityManager,
        );

        $redirect = '/aacp/advanced/management?tab='.$tab;
        if ($invalidKey !== null) {
            return new RedirectResponse($redirect.'&invalid_setting='.urlencode($invalidKey));
        }

        return new RedirectResponse($redirect.'&saved=1');
    }

    #[Route('/aacp/advanced/management/test-email', name: 'aacp_advanced_management_test_email', methods: ['POST'])]
    #[IsGranted('system.aacp.access')]
    public function testEmail(Request $request): RedirectResponse
    {
        $submittedToken = (string) $request->request->get('_token');
        if (!$this->csrfTokenManager->isTokenValid(new CsrfToken(SystemSettingsService::CSRF_TOKEN_ID, $submittedToken))) {
            throw new BadRequestHttpException($this->translator->trans('aacp.system.invalid_csrf'));
        }

        $to = trim((string) $request->request->get('test_email'));
        $tab = 'email';

        if ($to === '' || !filter_var($to, \FILTER_VALIDATE_EMAIL)) {
            return new RedirectResponse('/aacp/advanced/management?tab='.$tab.'&mail_error=invalid');
        }

        if (!$this->mailerService->canSend()) {
            return new RedirectResponse('/aacp/advanced/management?tab='.$tab.'&mail_error=not_configured');
        }

        try {
            $this->mailerService->sendTestEmail($to);
        } catch (\Throwable) {
            return new RedirectResponse('/aacp/advanced/management?tab='.$tab.'&mail_error=send_failed');
        }

        return new RedirectResponse('/aacp/advanced/management?tab='.$tab.'&mail_sent=1');
    }

    private function renderPlaceholder(string $title, ?string $description = null): Response
    {
        $html = $this->twig->render('aacp/placeholder.html.twig', [
            'title' => $title,
            'description' => $description,
        ]);

        return new Response($html);
    }
}
