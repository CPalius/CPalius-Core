<?php

declare(strict_types=1);

namespace Modules\Forum\Twig;

use App\Core\Account\UserAvatarService;
use App\Entity\User;
use App\Repository\UserRepository;
use Modules\Forum\Entity\ForumNotification;
use Modules\Forum\Entity\ForumSection;
use Modules\Forum\Repository\ForumNotificationRepository;
use Modules\Forum\Service\ForumNotificationService;
use Modules\Forum\Service\ForumPresenceService;
use Modules\Forum\Service\ForumReputationService;
use Modules\Forum\Service\ForumSectionHierarchyService;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Routing\Exception\RouteNotFoundException;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;
use Twig\TwigFunction;

/**
 * Forum Twig helpers: notifications, time formatting, number format.
 */
final class ForumTwigExtension extends AbstractExtension
{
    public function __construct(
        private readonly ForumNotificationService $notificationService,
        private readonly ForumReputationService $reputationService,
        private readonly ForumSectionHierarchyService $hierarchyService,
        private readonly UserAvatarService $avatarService,
        private readonly Security $security,
        private readonly TranslatorInterface $translator,
        private readonly ?ForumNotificationRepository $notificationRepository = null,
        private readonly ?ForumPresenceService $presenceService = null,
        private readonly ?UserRepository $userRepository = null,
        private readonly ?RequestStack $requestStack = null,
        private readonly ?UrlGeneratorInterface $urlGenerator = null,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('forum_unread_notification_count', [$this, 'unreadNotificationCount']),
            new TwigFunction('forum_recent_notifications', [$this, 'recentNotifications']),
            new TwigFunction('forum_board_stats', [$this, 'boardStats']),
            new TwigFunction('forum_online_presence', [$this, 'onlinePresence']),
            new TwigFunction('forum_online_url', [$this, 'onlineUrl']),
            new TwigFunction('forum_newest_member', [$this, 'newestMember']),
            new TwigFunction('forum_reputation_enabled', [$this, 'reputationEnabled']),
            new TwigFunction('forum_section_children', [$this, 'sectionChildren']),
            new TwigFunction('forum_user_avatar_url', [$this, 'userAvatarUrl']),
        ];
    }

    public function getFilters(): array
    {
        return [
            new TwigFilter('forum_relative_time', [$this, 'relativeTime']),
            new TwigFilter('forum_number', [$this, 'formatNumber']),
        ];
    }

    public function unreadNotificationCount(): int
    {
        $user = $this->security->getUser();
        if (!$user instanceof User) {
            return 0;
        }

        return $this->notificationService->countUnread($user);
    }

    /**
     * @return list<ForumNotification>
     */
    public function recentNotifications(int $limit = 6): array
    {
        $user = $this->security->getUser();
        if (!$user instanceof User || $this->notificationRepository === null) {
            return [];
        }

        return $this->notificationRepository->findRecentForUser($user, $limit);
    }

    /** @return array{topics: int, posts: int, users: int} */
    public function boardStats(): array
    {
        $locale = $this->requestStack?->getCurrentRequest()?->getLocale() ?? 'tr';

        return $this->hierarchyService->aggregateStats($locale);
    }

    /**
     * @return array{members: list<array<string, mixed>>, memberCount: int, guestCount: int}
     */
    public function onlinePresence(): array
    {
        return $this->presenceService?->currentOnline() ?? [
            'members' => [],
            'memberCount' => 0,
            'guestCount' => 0,
        ];
    }

    public function onlineUrl(): string
    {
        try {
            if ($this->urlGenerator !== null) {
                return $this->urlGenerator->generate('forum_online');
            }
        } catch (RouteNotFoundException) {
        }

        $locale = $this->requestStack?->getCurrentRequest()?->getLocale() ?? 'tr';

        return '/'.$locale.'/forums/cevrimici';
    }

    /**
     * @return array{name: string, slug: string, user: User}|null
     */
    public function newestMember(): ?array
    {
        $user = $this->userRepository?->findNewestActive();
        if ($user === null) {
            return null;
        }

        $username = trim((string) ($user->getUsername() ?? ''));
        $fullName = trim($user->getFullName());
        $name = $username !== ''
            ? $username
            : ($fullName !== '' && $fullName !== $user->getEmail() ? $fullName : $user->getEmail());

        return [
            'name' => $name,
            'slug' => $user->getProfileSlug(),
            'user' => $user,
        ];
    }

    public function reputationEnabled(): bool
    {
        return $this->reputationService->isEnabled();
    }

    /**
     * @return list<ForumSection>
     */
    public function sectionChildren(ForumSection $section): array
    {
        return $this->hierarchyService->getSortedChildren($section);
    }

    public function userAvatarUrl(?User $user): ?string
    {
        return $this->avatarService->resolveUrl($user);
    }

    public function relativeTime(?\DateTimeInterface $date): string
    {
        if ($date === null) {
            return '';
        }

        $now = new \DateTimeImmutable();
        $diff = $now->getTimestamp() - $date->getTimestamp();

        if ($diff < 60) {
            return $this->translator->trans('time.just_now', domain: 'forums');
        }
        if ($diff < 3600) {
            $m = (int) floor($diff / 60);

            return $this->translator->trans('time.minutes_ago', ['count' => $m], 'forums');
        }
        if ($diff < 86400) {
            $h = (int) floor($diff / 3600);

            return $this->translator->trans('time.hours_ago', ['count' => $h], 'forums');
        }
        if ($diff < 604800) {
            $d = (int) floor($diff / 86400);

            return $this->translator->trans('time.days_ago', ['count' => $d], 'forums');
        }

        return $date->format('d.m.Y H:i');
    }

    public function formatNumber(int|float $value): string
    {
        if ($value >= 1000000) {
            return round($value / 1000000, 1) . 'M';
        }
        if ($value >= 1000) {
            return round($value / 1000, 1) . 'K';
        }

        return number_format((int) $value, 0, ',', '.');
    }
}
