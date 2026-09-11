<?php

declare(strict_types=1);

namespace App\Core\Security\EventListener;

use App\Core\Security\Service\LoginDefenseService;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAuthenticationException;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Symfony\Component\Security\Http\Event\CheckPassportEvent;
use Symfony\Component\Security\Http\Event\LoginFailureEvent;
use Symfony\Component\Security\Http\Event\LoginSuccessEvent;

/**
 * Wires LoginDefenseService into the firewall.
 *
 * Hooking the authenticator events rather than a POST path means every current and
 * future authenticator is covered, and the block happens before the password hash
 * is ever computed — so a locked account costs an attacker no CPU of ours.
 */
final class LoginDefenseSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly LoginDefenseService $defense,
        private readonly RequestStack $requestStack,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            // Ahead of the credential checker, which listens at priority 0.
            CheckPassportEvent::class => ['onCheckPassport', 2048],
            LoginFailureEvent::class => 'onLoginFailure',
            LoginSuccessEvent::class => 'onLoginSuccess',
        ];
    }

    public function onCheckPassport(CheckPassportEvent $event): void
    {
        $identifier = $this->submittedIdentifier($event->getPassport());

        if (!$this->defense->isBlocked($this->clientIp(), $identifier)) {
            return;
        }

        // One generic message for both scopes: telling the client which counter
        // tripped would confirm that the account exists.
        throw new CustomUserMessageAuthenticationException('account.login.throttled');
    }

    public function onLoginFailure(LoginFailureEvent $event): void
    {
        $passport = $event->getPassport();

        $this->defense->registerFailure(
            $this->clientIp(),
            $passport === null ? '' : $this->submittedIdentifier($passport),
        );
    }

    public function onLoginSuccess(LoginSuccessEvent $event): void
    {
        $this->defense->registerSuccess(
            $this->clientIp(),
            $this->identifier($event->getAuthenticatedToken()->getUserIdentifier()),
        );
    }

    /**
     * Reads the submitted login from the badge instead of resolving the user: the
     * pre-auth check must not hit the user provider, or a locked-out attacker would
     * still get us to run a query — and the response time would leak whether the
     * account exists.
     *
     * Because email-or-username login means one account has two identifiers, the
     * per-IP counter is what stops an attacker from alternating between them.
     */
    private function submittedIdentifier(Passport $passport): string
    {
        $badge = $passport->getBadge(UserBadge::class);

        return $badge instanceof UserBadge ? $this->identifier($badge->getUserIdentifier()) : '';
    }

    private function identifier(string $raw): string
    {
        return mb_strtolower(trim($raw));
    }

    private function clientIp(): string
    {
        return (string) ($this->requestStack->getMainRequest()?->getClientIp() ?? '');
    }
}
