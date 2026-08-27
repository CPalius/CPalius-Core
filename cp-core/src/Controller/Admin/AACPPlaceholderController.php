<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Core\Annotation\CpAdminMenu;
use App\Core\Settings\SettingsRegistry;
use App\Entity\Setting;
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

    #[Route('/aacp/themes', name: 'aacp_themes', methods: ['GET'])]
    #[CpAdminMenu(label: 'aacp.menu.themes', icon: 'heroicons:swatch', panel: 'aacp', priority: 70, capability: 'system.aacp.access', group: 'aacp.group.appearance')]
    #[IsGranted('system.aacp.access')]
    public function themes(): Response
    {
        return $this->renderPlaceholder('Temalar');
    }

    #[Route('/aacp/themes/options', name: 'aacp_theme_options', methods: ['GET'])]
    #[CpAdminMenu(label: 'aacp.menu.theme_options', icon: 'heroicons:adjustments-horizontal', panel: 'aacp', priority: 71, capability: 'system.aacp.access', parent: 'aacp_themes')]
    #[IsGranted('system.aacp.access')]
    public function themeOptions(): Response
    {
        return $this->renderPlaceholder('Tema Seçenekleri');
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
    public function advancedManagement(): Response
    {
        $settingGroups = [];
        $currentValues = [];

        foreach ($this->settingsRegistry->all() as $definition) {
            if ($definition->module !== 'core') {
                continue;
            }

            $settingGroups[$definition->group][] = $definition;
            $currentValues[$definition->key] = $this->settingsRegistry->get($definition->key);
        }

        $html = $this->twig->render('aacp/management.html.twig', [
            'settingGroups' => $settingGroups,
            'currentValues' => $currentValues,
            'locales' => $this->localeRepository->findBy([], ['sortOrder' => 'ASC']),
            'csrf_token' => $this->csrfTokenManager->getToken('aacp_core_settings')->getValue(),
            'locales_csrf_token' => $this->csrfTokenManager->getToken('aacp_locales')->getValue(),
        ]);

        return new Response($html);
    }

    /**
     * AACPController::updateSettings() ile AYNI mantık, ama sadece
     * module==='core' ayarlarına daraltılmış — "Genel Ayarlar" bölümünün
     * kendi POST ucu (CSRF token id'si de ayrı: 'aacp_core_settings').
     */
    #[Route('/aacp/advanced/management/update', name: 'aacp_advanced_management_update', methods: ['POST'])]
    #[IsGranted('system.aacp.access')]
    public function updateCoreSettings(Request $request): RedirectResponse
    {
        $submittedToken = (string) $request->request->get('_token');
        if (!$this->csrfTokenManager->isTokenValid(new CsrfToken('aacp_core_settings', $submittedToken))) {
            throw new BadRequestHttpException($this->translator->trans('aacp.system.invalid_csrf'));
        }

        /** @var array<string, string> $submitted */
        $submitted = $request->request->all('settings');

        $coreDefinitions = array_values(array_filter(
            $this->settingsRegistry->all(),
            static fn ($definition) => $definition->module === 'core',
        ));

        $keys = array_map(static fn ($definition) => $definition->key, $coreDefinitions);
        $existing = $this->settingRepository->findIndexedByKeys($keys);

        foreach ($coreDefinitions as $definition) {
            $raw = $submitted[$definition->key] ?? null;

            $value = match ($definition->type) {
                'checkbox' => $raw !== null ? '1' : '0',
                default => $raw !== null ? trim($raw) : null,
            };

            if ($value === null) {
                continue;
            }

            $setting = $existing[$definition->key] ?? null;
            if (!$setting instanceof Setting) {
                $setting = new Setting($definition->key, $definition->module);
                $this->entityManager->persist($setting);
            }

            $setting->setSettingValue($value);
        }

        $this->entityManager->flush();
        $this->settingsRegistry->clearCache();

        return new RedirectResponse('/aacp/advanced/management');
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
