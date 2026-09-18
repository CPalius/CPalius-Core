<?php

declare(strict_types=1);

namespace App\Core\Security\TwoFactor;

use App\Entity\User;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Security\Http\Event\LoginSuccessEvent;

/**
 * Holds an authenticated-but-unverified session at the second-factor challenge,
 * and pushes privileged accounts into enrollment when 2FA is mandatory.
 *
 * Runs after the firewall has populated the token but before the controller, so
 * every route is covered without each controller having to remember the check.
 */
final class TwoFactorGuardSubscriber implements EventSubscriberInterface
{
    public const CHALLENGE_PATH = '/hesap/iki-adimli-dogrulama';
    public const SETUP_PATH = '/hesap/iki-adimli-kurulum';

    /** Paths that must stay reachable, or the challenge itself becomes unreachable. */
    private const ALLOWED_PREFIXES = [
        self::CHALLENGE_PATH,
        self::SETUP_PATH,
        '/logout',
        '/hesap/cikis',
        '/aacp/recovery',
        '/_wdt',
        '/_profiler',
        '/assets/',
        '/build/',
        '/uploads/',
    ];

    public function __construct(
        private readonly Security $security,
        private readonly TwoFactorService $twoFactor,
        private readonly TwoFactorSession $twoFactorSession,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            // Firewall listener sits at 8; this must see the resolved token.
            KernelEvents::REQUEST => ['onKernelRequest', 6],
            LoginSuccessEvent::class => 'onLoginSuccess',
        ];
    }

    /**
     * Every sign-in asks for the second factor again.
     *
     * The session id is migrated on login but its attributes are not dropped, so
     * a "verified" mark left by an earlier sign-in in the same browser would
     * otherwise be honoured for the new one.
     */
    public function onLoginSuccess(LoginSuccessEvent $event): void
    {
        try {
            $request = $event->getRequest();
            if ($request->hasSession() && $request->getSession()->isStarted()) {
                $this->twoFactorSession->clear($request->getSession());
            }
        } catch (\Throwable) {
            // The guard re-checks on the next request either way.
        }
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();

        try {
            if ($this->isAllowed($request) || !$this->twoFactor->isEnabledGlobally()) {
                return;
            }

            $user = $this->security->getUser();
            if (!$user instanceof User) {
                return;
            }

            if (!$request->hasSession()) {
                return;
            }

            $session = $request->getSession();

            if ($this->twoFactor->isEnrolled($user)) {
                if ($this->twoFactorSession->isVerified($session)) {
                    return;
                }

                $event->setResponse($this->challenge($request, self::CHALLENGE_PATH));

                return;
            }

            // An account whose sealed secret no longer opens is not an account
            // without a second factor — it is an account whose second factor broke.
            // Sending it to setup is the only fail-closed answer that does not also
            // strand the owner: the challenge would reject every code they own.
            if ($this->twoFactor->isEnrollmentBroken($user)) {
                $event->setResponse($this->challenge($request, self::SETUP_PATH));

                return;
            }

            if ($this->twoFactor->isEnrollmentRequired($user)) {
                $event->setResponse($this->challenge($request, self::SETUP_PATH));
            }
        } catch (\Throwable) {
            // A broken second-factor gate must not lock the site; the challenge
            // routes themselves still refuse to let an unverified session through.
        }
    }

    private function isAllowed(Request $request): bool
    {
        $path = $request->getPathInfo();

        foreach (self::ALLOWED_PREFIXES as $prefix) {
            if (str_starts_with($path, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * A redirect would be meaningless to a fetch() caller, so API-ish requests get
     * a status code they can act on instead.
     */
    private function challenge(Request $request, string $path): Response
    {
        if ($request->isXmlHttpRequest() || str_contains((string) $request->headers->get('Accept'), 'application/json')) {
            return new JsonResponse(['error' => 'two_factor_required', 'location' => $path], Response::HTTP_FORBIDDEN);
        }

        return new RedirectResponse($path);
    }
}
