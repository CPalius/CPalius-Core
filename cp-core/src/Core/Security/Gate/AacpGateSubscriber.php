<?php

declare(strict_types=1);

namespace App\Core\Security\Gate;

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
use Symfony\Component\Security\Http\Event\LogoutEvent;

/**
 * Holds every /aacp request at the gate question until this session has answered it.
 *
 * Runs at 5: after the firewall (8), the session guard (7) and the second-factor
 * gate (6), so an expired or unverified session is dealt with first and the
 * question is only ever asked of somebody we already know is who they say.
 */
final class AacpGateSubscriber implements EventSubscriberInterface
{
    private const PROTECTED_PREFIX = '/aacp';

    /** Reachable without answering, or the question itself becomes unreachable. */
    private const ALLOWED_PREFIXES = [
        AacpGate::GATE_PATH,
        '/aacp/recovery',
        '/logout',
        '/hesap/cikis',
    ];

    public function __construct(
        private readonly Security $security,
        private readonly AacpGate $gate,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST => ['onKernelRequest', 5],
            LogoutEvent::class => 'onLogout',
            LoginSuccessEvent::class => 'onLoginSuccess',
        ];
    }

    /** A new sign-in must answer again; the pass never outlives the session that earned it. */
    public function onLogout(LogoutEvent $event): void
    {
        $this->forget($event->getRequest());
    }

    /**
     * Symfony's session strategy migrates the id on login but keeps the
     * attributes, so without this a pass earned before the sign-in would still
     * be sitting there afterwards.
     */
    public function onLoginSuccess(LoginSuccessEvent $event): void
    {
        $this->forget($event->getRequest());
    }

    private function forget(Request $request): void
    {
        try {
            if ($request->hasSession() && $request->getSession()->isStarted()) {
                $this->gate->clear($request->getSession());
            }
        } catch (\Throwable) {
            // Session invalidation drops the key anyway.
        }
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();

        try {
            if (!$this->isProtected($request) || !$this->gate->isActive()) {
                return;
            }

            if (!$this->security->getUser() instanceof User || !$request->hasSession()) {
                return;
            }

            if ($this->gate->isPassed($request->getSession())) {
                return;
            }

            $event->setResponse($this->challenge($request));
        } catch (\Throwable) {
            // A gate that cannot decide must not take the panel down with it;
            // the gate controller re-checks the session state on its own.
        }
    }

    private function isProtected(Request $request): bool
    {
        $path = $request->getPathInfo();

        // "/aacpanel" is not "/aacp": match the segment, not the substring.
        if ($path !== self::PROTECTED_PREFIX && !str_starts_with($path, self::PROTECTED_PREFIX.'/')) {
            return false;
        }

        foreach (self::ALLOWED_PREFIXES as $prefix) {
            if ($path === $prefix || str_starts_with($path, $prefix.'/')) {
                return false;
            }
        }

        return true;
    }

    private function challenge(Request $request): Response
    {
        if ($request->isXmlHttpRequest() || str_contains((string) $request->headers->get('Accept'), 'application/json')) {
            return new JsonResponse(['error' => 'aacp_gate_required', 'location' => AacpGate::GATE_PATH], Response::HTTP_FORBIDDEN);
        }

        $target = $request->getRequestUri();

        return new RedirectResponse(AacpGate::GATE_PATH.'?next='.rawurlencode($target));
    }
}
