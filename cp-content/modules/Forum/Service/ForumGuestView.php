<?php

declare(strict_types=1);

namespace Modules\Forum\Service;

use App\Core\Settings\SettingsRegistry;
use App\Entity\User;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Guest-facing forum restrictions: a slimmer postbit and hidden post bodies.
 *
 * Search-engine spiders keep the full page so the board stays indexable.
 * That is the point of these toggles — they are not a lock (forum.allow_guest_view
 * still owns that).
 */
final class ForumGuestView
{
    public function __construct(
        private readonly SettingsRegistry $settingsRegistry,
        private readonly Security $security,
        private readonly RequestStack $requestStack,
    ) {
    }

    public function hidePostbitExtras(): bool
    {
        return ForumGuestPolicy::hideFromVisitor(
            (bool) $this->settingsRegistry->get('forum.guest_hide_postbit', false),
            $this->visitorKind(),
        );
    }

    public function hidePostBodies(): bool
    {
        return ForumGuestPolicy::hideFromVisitor(
            (bool) $this->settingsRegistry->get('forum.guest_hide_content', false),
            $this->visitorKind(),
        );
    }

    /**
     * Site admin (CPalius `admin` role) or anyone who can moderate topics.
     * Spoilers are never hidden from them — they are staff.
     */
    public function isStaff(): bool
    {
        if ($this->security->isGranted('forum.topic.moderate')) {
            return true;
        }

        $user = $this->security->getUser();
        if (!$user instanceof User) {
            return false;
        }

        return \in_array('admin', $user->getCpaliusRoles(), true);
    }

    /**
     * @return ForumVisitorKind::MEMBER|ForumVisitorKind::GUEST|ForumVisitorKind::SPIDER|ForumVisitorKind::BOT
     */
    public function visitorKind(): string
    {
        $request = $this->requestStack->getCurrentRequest();
        $userAgent = $request !== null ? (string) $request->headers->get('User-Agent', '') : '';
        $user = $this->security->getUser();

        return ForumVisitorKind::classify($user instanceof User, $userAgent);
    }
}
