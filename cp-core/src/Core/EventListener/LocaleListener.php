<?php

namespace App\Core\EventListener;

use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * URL'nin ilk segmentindeki dil kodunu (/tr/... veya /en/...) yakalayıp
 * Request'in locale'ini set eder.
 *
 * Symfony'nin RouterListener'ı zaten bir route "_locale" parametresi
 * tanımlıyorsa bunu otomatik Request::setLocale() ile işler (bkz.
 * routing.yaml'daki {_locale} prefix'i + Blog modülünün route'ları).
 * Bu listener'ın asıl işi, RouterListener'dan ÖNCE (daha yüksek
 * öncelikte) çalışıp desteklenmeyen/eksik bir dil kodu durumunda
 * varsayılan dile (tr) sessizce düşmeyi garanti etmektir — böylece
 * "/xx/blog-test" gibi tanımsız bir dil kodu 404 yerine varsayılan
 * dile düşer.
 *
 * AACP (/aacp/...) için URL prefix stratejisi UYGULANAMAZ (rotalar sabit
 * "/aacp/..." öneki taşır, "/tr/aacp/..." biçiminde değildir). Bu yüzden
 * admin arayüz dili için ayrıca session'da "_aacp_locale" anahtarı
 * kullanılır (bkz. AacpLocaleController::switch()); bu anahtar varsa ve
 * desteklenen bir dile işaret ediyorsa, URL prefix kontrolünden ÖNCE
 * (AACP path'leri için) uygulanır.
 */
final class LocaleListener implements EventSubscriberInterface
{
    private const URL_LOCALE_PATTERN = '#^/(?P<locale>[a-z]{2})(?:/|$)#';
    public const AACP_PATH_PREFIX = '/aacp';
    public const SESSION_KEY = '_aacp_locale';

    public function __construct(
        private readonly string $defaultLocale,
        private readonly array $supportedLocales,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            // RouterListener (öncelik 32) çalışmadan önce devreye girmeli
            // ki _locale route parametresi bizim belirlediğimiz locale'i
            // ezip geçebilsin (route eşleşirse route her zaman kazanır).
            KernelEvents::REQUEST => [['onKernelRequest', 40]],
        ];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        $path = $request->getPathInfo();

        if (str_starts_with($path, self::AACP_PATH_PREFIX) && $request->hasPreviousSession()) {
            $sessionLocale = $request->getSession()->get(self::SESSION_KEY);

            if (\is_string($sessionLocale) && \in_array($sessionLocale, $this->supportedLocales, true)) {
                $request->setLocale($sessionLocale);

                return;
            }
        }

        if (preg_match(self::URL_LOCALE_PATTERN, $path, $matches) && \in_array($matches['locale'], $this->supportedLocales, true)) {
            $request->setLocale($matches['locale']);

            return;
        }

        // URL'de desteklenen bir dil kodu yok: varsayılan dili kullan.
        // (Bilinçli tasarım kararı: burada bir Redirect yapmıyoruz çünkü
        // "/blog-test" gibi henüz {_locale} prefix'i olmayan route'lar da
        // olabilir — bu durumda sadece Request::locale set edilir, route
        // eşleşmesi kendi akışında devam eder.)
        $request->setLocale($this->defaultLocale);
    }
}
