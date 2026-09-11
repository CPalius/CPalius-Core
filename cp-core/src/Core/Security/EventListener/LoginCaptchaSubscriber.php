<?php

declare(strict_types=1);

namespace App\Core\Security\EventListener;

use App\Core\Security\CaptchaService;
use App\Core\Security\Service\LoginDefenseService;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAuthenticationException;
use Symfony\Component\Security\Http\SecurityRequestAttributes;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Captcha check on login POST, before the security firewall.
 */
final class LoginCaptchaSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly CaptchaService $captchaService,
        private readonly LoginDefenseService $loginDefense,
        private readonly TranslatorInterface $translator,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST => ['onKernelRequest', 9],
        ];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        if ($request->getPathInfo() !== '/login' || !$request->isMethod('POST')) {
            return;
        }

        if (!$this->loginDefense->captchaRequiredOnLogin((string) ($request->getClientIp() ?? ''))) {
            return;
        }

        if ($this->captchaService->verifyRequest($request)) {
            return;
        }

        $session = $request->getSession();
        $session->set(
            SecurityRequestAttributes::AUTHENTICATION_ERROR,
            new CustomUserMessageAuthenticationException($this->translator->trans('account.captcha.failed')),
        );
        $session->set(SecurityRequestAttributes::LAST_USERNAME, (string) $request->request->get('_username', ''));

        $event->setResponse(new RedirectResponse('/hesap/giris'));
    }
}
