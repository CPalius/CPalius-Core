<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Core\EventListener\LocaleListener;
use App\Core\Localization\LocaleProvider;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * AACP arayüz dilini değiştirir.
 *
 * FAZ 3: seçim artık SESSION'da değil, LocaleListener ile aynı cp_locale
 * ÇEREZİNDE saklanır (Manifesto Law 6.4 — sessionless trafik). Böylece
 * panelde seçilen dil ön yüzde de geçerli olur; iki ayrı "arayüz dili" ve
 * "site dili" kavramı kalmaz.
 *
 * Rota kısıtı %cpalius.locales_pattern% ile DERLEME ZAMANINDA üretilir
 * (bkz. LocalesPatternPass) — yeni bir dil eklendiğinde burada elle
 * güncellenecek sabit bir 'tr|en' listesi YOKTUR. Yine de çalışma
 * zamanında LocaleProvider ile ikinci kez doğrulanır: container henüz
 * yeniden derlenmemişse desen ile aktif liste ayrışabilir.
 *
 * Desteklenmeyen bir kod sessizce yok sayılıp mevcut sayfaya dönülür —
 * sadece bir dil linkine tıklayan kullanıcıya 400 göstermek orantısız
 * olurdu.
 */
final class AACPLocaleController extends AbstractController
{
    public function __construct(
        private readonly LocaleProvider $localeProvider,
        private readonly LocaleListener $localeListener,
    ) {
    }

    #[Route(
        '/aacp/locale/{locale}',
        name: 'aacp_locale_switch',
        methods: ['GET'],
        requirements: ['locale' => '%cpalius.locales_pattern%'],
    )]
    public function switch(string $locale, Request $request): RedirectResponse
    {
        $response = $this->buildRedirect($request);

        if ($this->localeProvider->isSupported($locale)) {
            $response->headers->setCookie($this->localeListener->buildCookie($locale, $request));

            // Eski oturum anahtarı çerezle çelişmesin: oturum ZATEN açıksa
            // (yeni bir oturum başlatmadan) senkron tutulur.
            if ($request->hasPreviousSession()) {
                $request->getSession()->set(LocaleListener::SESSION_KEY, $locale);
            }
        }

        return $response;
    }

    private function buildRedirect(Request $request): RedirectResponse
    {
        $referer = $request->headers->get('referer');

        // Open-redirect koruması: yalnızca kendi host'umuza dönülür.
        if (\is_string($referer) && $referer !== '') {
            $host = parse_url($referer, \PHP_URL_HOST);

            if ($host === null || $host === false || $host === $request->getHost()) {
                return new RedirectResponse($referer);
            }
        }

        return $this->redirectToRoute('aacp_dashboard');
    }
}
