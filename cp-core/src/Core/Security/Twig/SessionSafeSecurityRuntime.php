<?php

declare(strict_types=1);

namespace App\Core\Security\Twig;

use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\Security\Core\User\UserInterface;
use Twig\Extension\RuntimeExtensionInterface;

/**
 * app.user / is_granted / app.flashes start a session on anonymous hits (Law 6.4).
 * These helpers no-op unless the request already carries a session cookie.
 */
final class SessionSafeSecurityRuntime implements RuntimeExtensionInterface
{
    public function __construct(
        private readonly RequestStack $requestStack,
        private readonly Security $security,
    ) {
    }

    public function user(): ?UserInterface
    {
        if (!$this->hasExistingSession()) {
            return null;
        }

        return $this->security->getUser();
    }

    public function isGranted(string $attribute, mixed $subject = null): bool
    {
        if (!$this->hasExistingSession()) {
            return false;
        }

        return $this->security->isGranted($attribute, $subject);
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function flashes(): array
    {
        if (!$this->hasExistingSession()) {
            return [];
        }

        $session = $this->requestStack->getSession();

        if (!$session instanceof Session) {
            return [];
        }

        /** @var array<string, list<mixed>> $all */
        $all = $session->getFlashBag()->all();

        return $all;
    }

    /**
     * True when the client already sent a session cookie. Does not start a session.
     */
    private function hasExistingSession(): bool
    {
        $request = $this->requestStack->getCurrentRequest();

        return $request !== null && $request->hasPreviousSession();
    }
}
