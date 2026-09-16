<?php

declare(strict_types=1);

namespace Modules\Messages\Twig;

use App\Entity\User;
use Modules\Messages\Admin\MessagesDesk;
use Modules\Messages\Entity\Message;
use Modules\Messages\Repository\MessageParticipantRepository;
use Modules\Messages\Service\MessagesAccess;
use Modules\Messages\Service\MessagesConfig;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Routing\Exception\ExceptionInterface as RoutingException;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Twig\Extension\RuntimeExtensionInterface;

final class MessagesRuntime implements RuntimeExtensionInterface
{
    public function __construct(
        private readonly MessageParticipantRepository $participants,
        private readonly MessagesAccess $access,
        private readonly MessagesConfig $config,
        private readonly Security $security,
        private readonly UrlGeneratorInterface $urlGenerator,
        private readonly RequestStack $requestStack,
    ) {
    }

    public function unread(?User $user = null): int
    {
        if (!$this->config->enabled()) {
            return 0;
        }

        $user ??= $this->security->getUser() instanceof User ? $this->security->getUser() : null;
        if (!$user instanceof User) {
            return 0;
        }

        try {
            return $this->participants->unreadTotal($user);
        } catch (\Throwable) {
            return 0;
        }
    }

    /**
     * @param array{context_type?: string, context_id?: int, context_label?: string, context_url?: string} $context
     */
    public function composeUrl(?User $recipient, array $context = []): string
    {
        if (!$recipient instanceof User || !$this->config->enabled()) {
            return '';
        }

        try {
            $params = ['to' => $recipient->getUsername() ?: (string) $recipient->getId()];
            foreach (['context_type', 'context_id', 'context_label', 'context_url'] as $key) {
                if (isset($context[$key]) && $context[$key] !== '' && $context[$key] !== null) {
                    $params[$key] = $context[$key];
                }
            }

            $request = $this->requestStack->getCurrentRequest();
            if ($request !== null) {
                $params['_locale'] = $request->getLocale();
            }

            return $this->urlGenerator->generate('messages_compose', $params);
        } catch (RoutingException) {
            return '';
        }
    }

    public function canReport(?Message $message): bool
    {
        $user = $this->security->getUser();
        if (!$user instanceof User || !$message instanceof Message) {
            return false;
        }

        return $this->access->canReport($user, $message);
    }

    /**
     * @return list<array{id: string, label: string, icon: string, route: string, active: bool}>
     */
    public function deskTabs(): array
    {
        $request = $this->requestStack->getCurrentRequest();
        $route = $request?->attributes->get('_route');
        $active = \is_string($route) ? MessagesDesk::tabForRoute($route) : MessagesDesk::TAB_OVERVIEW;
        $tabs = [];

        foreach (MessagesDesk::tabs() as $tab) {
            if (!$this->security->isGranted($tab['capability'])) {
                continue;
            }

            unset($tab['capability']);
            $tab['active'] = $tab['id'] === $active;
            $tabs[] = $tab;
        }

        return $tabs;
    }
}
