<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Core\EventListener\LocaleListener;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * AACP arayüz dilini (session bazlı) değiştirir. AACP rotaları sabit
 * "/aacp/..." öneki taşıdığı için front-end'deki {_locale} URL prefix
 * stratejisi burada uygulanamaz — bkz. LocaleListener docblock'u.
 *
 * Sadece desteklenen locale'lere (tr/en) izin verilir; başka bir değer
 * sessizce yok sayılıp mevcut sayfaya geri dönülür (LogicException/400
 * fırlatmak, sadece bir dil linkine tıklayan kullanıcı için orantısız
 * olurdu).
 */
final class AACPLocaleController extends AbstractController
{
    private const SUPPORTED_LOCALES = ['tr', 'en'];

    #[Route('/aacp/locale/{locale}', name: 'aacp_locale_switch', methods: ['GET'], requirements: ['locale' => 'tr|en'])]
    public function switch(string $locale, Request $request): RedirectResponse
    {
        if (\in_array($locale, self::SUPPORTED_LOCALES, true)) {
            $request->getSession()->set(LocaleListener::SESSION_KEY, $locale);
        }

        $referer = $request->headers->get('referer');

        if (\is_string($referer) && $referer !== '') {
            return new RedirectResponse($referer);
        }

        return $this->redirectToRoute('aacp_dashboard', [], UrlGeneratorInterface::ABSOLUTE_URL);
    }
}
