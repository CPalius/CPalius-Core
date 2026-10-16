<?php

declare(strict_types=1);

namespace App\Core\Security\Reauth;

use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ControllerArgumentsEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Security\Http\Authenticator\RememberMeAuthenticator;
use Symfony\Component\Security\Http\Event\LoginSuccessEvent;
use Symfony\Component\Security\Http\Event\LogoutEvent;

/**
 * Enforces #[RequiresRecentAuth]: a stale session is sent to the confirmation screen instead of reaching the
 * action. A form post is not replayed afterwards (its fields would have to be kept in the session); the person
 * lands back on the page the form was on and submits it again, now inside the fresh window.
 */
final class RecentAuthSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly RecentAuth $recentAuth,
        private readonly Security $security,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::CONTROLLER_ARGUMENTS => 'onController',
            LoginSuccessEvent::class => 'onLogin',
            LogoutEvent::class => 'onLogout',
        ];
    }

    /** A password sign-in counts; a remember-me cookie is the very thing this is meant not to trust. */
    public function onLogin(LoginSuccessEvent $event): void
    {
        $session = $event->getRequest()->hasSession() ? $event->getRequest()->getSession() : null;
        if ($session === null) {
            return;
        }

        if ($event->getAuthenticator() instanceof RememberMeAuthenticator) {
            $this->recentAuth->forget($session);

            return;
        }

        $this->recentAuth->mark($session);
    }

    public function onLogout(LogoutEvent $event): void
    {
        if ($event->getRequest()->hasSession()) {
            $this->recentAuth->forget($event->getRequest()->getSession());
        }
    }

    public function onController(ControllerArgumentsEvent $event): void
    {
        $attributes = $event->getAttributes(RequiresRecentAuth::class);
        if (!$event->isMainRequest() || $attributes === []) {
            return;
        }

        $request = $event->getRequest();
        if ($attributes[0]->writesOnly && $request->isMethodSafe()) {
            return;
        }

        if (!$request->hasSession() || $this->security->getUser() === null || $this->recentAuth->isFresh($request->getSession())) {
            return;
        }

        $confirm = RecentAuth::CONFIRM_PATH.'?next='.rawurlencode($this->returnPath($request));
        $response = $this->wantsJson($request)
            ? new JsonResponse(['error' => 'reauth_required', 'confirm' => $confirm], Response::HTTP_FORBIDDEN)
            : new RedirectResponse($confirm);

        $event->setController(static fn (): Response => $response);
    }

    /** Where to come back to: the page itself for a GET, the page the form was on for anything else. */
    private function returnPath(Request $request): string
    {
        if ($request->isMethod('GET')) {
            return $request->getRequestUri();
        }

        $referer = (string) $request->headers->get('Referer', '');
        $parts = parse_url($referer);
        if (\is_array($parts) && ($parts['host'] ?? '') === $request->getHost() && str_starts_with((string) ($parts['path'] ?? ''), '/aacp')) {
            return $parts['path'].(isset($parts['query']) ? '?'.$parts['query'] : '');
        }

        return '/aacp';
    }

    private function wantsJson(Request $request): bool
    {
        return $request->isXmlHttpRequest() || str_contains((string) $request->headers->get('Accept', ''), 'application/json');
    }
}
