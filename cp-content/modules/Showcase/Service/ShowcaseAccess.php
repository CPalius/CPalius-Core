<?php

declare(strict_types=1);

namespace Modules\Showcase\Service;

use App\Entity\User;
use Modules\Showcase\Entity\ShowcaseItem;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * Capability questions the showcase asks, in one place.
 *
 * Every check goes through Security::isGranted() and therefore through
 * CPaliusVoter — no ROLE_* comparisons, no "is this my row" written by hand in a
 * controller (Manifesto Law 4). The pattern is always the same: ".any" wins
 * outright, ".own" is evaluated by the voter against OwnableInterface.
 */
final class ShowcaseAccess
{
    public const CAP_TYPE_MANAGE = 'showcase.type.manage';
    public const CAP_FIELD_MANAGE = 'showcase.field.manage';
    public const CAP_ITEM_CREATE = 'showcase.item.create';
    public const CAP_ITEM_VIEW_OWN = 'showcase.item.view.own';
    public const CAP_ITEM_VIEW_ANY = 'showcase.item.view.any';
    public const CAP_ITEM_EDIT_OWN = 'showcase.item.edit.own';
    public const CAP_ITEM_EDIT_ANY = 'showcase.item.edit.any';
    public const CAP_ITEM_DELETE_OWN = 'showcase.item.delete.own';
    public const CAP_ITEM_DELETE_ANY = 'showcase.item.delete.any';
    public const CAP_ITEM_PUBLISH = 'showcase.item.publish';
    public const CAP_ITEM_MODERATE = 'showcase.item.moderate';
    public const CAP_REVIEW_CREATE = 'showcase.review.create';
    public const CAP_REVIEW_MODERATE = 'showcase.review.moderate';

    public function __construct(
        private readonly Security $security,
        private readonly ShowcaseConfig $config,
    ) {
    }

    public function canCreate(): bool
    {
        return $this->security->isGranted(self::CAP_ITEM_CREATE);
    }

    public function canEdit(ShowcaseItem $item): bool
    {
        return $this->security->isGranted(self::CAP_ITEM_EDIT_ANY)
            || $this->security->isGranted(self::CAP_ITEM_EDIT_OWN, $item);
    }

    public function canDelete(ShowcaseItem $item): bool
    {
        return $this->security->isGranted(self::CAP_ITEM_DELETE_ANY)
            || $this->security->isGranted(self::CAP_ITEM_DELETE_OWN, $item);
    }

    /**
     * Who may see an entry that is not published: its owner, and anyone holding
     * the ".any" view capability or moderation rights.
     */
    public function canViewUnpublished(ShowcaseItem $item): bool
    {
        return $this->security->isGranted(self::CAP_ITEM_VIEW_ANY)
            || $this->security->isGranted(self::CAP_ITEM_MODERATE)
            || $this->security->isGranted(self::CAP_ITEM_VIEW_OWN, $item);
    }

    public function canView(ShowcaseItem $item): bool
    {
        return $item->isVisible() || $this->canViewUnpublished($item);
    }

    public function canModerate(): bool
    {
        return $this->security->isGranted(self::CAP_ITEM_MODERATE);
    }

    /**
     * Skips the moderation queue. Kept separate from canModerate() on purpose: a
     * trusted contributor may publish their own entries without gaining the right
     * to approve anyone else's.
     */
    public function canPublishDirectly(): bool
    {
        return $this->security->isGranted(self::CAP_ITEM_PUBLISH)
            || $this->security->isGranted(self::CAP_ITEM_MODERATE);
    }

    public function canManageTypes(): bool
    {
        return $this->security->isGranted(self::CAP_TYPE_MANAGE);
    }

    public function canManageFields(): bool
    {
        return $this->security->isGranted(self::CAP_FIELD_MANAGE);
    }

    public function canModerateReviews(): bool
    {
        return $this->security->isGranted(self::CAP_REVIEW_MODERATE);
    }

    /**
     * Reviews are off entirely when the module setting says so, when the type
     * does not use them, and — unless the operator allows it — for the owner of
     * the item, who should not be rating their own listing.
     */
    public function canReview(ShowcaseItem $item): bool
    {
        if (!$this->config->reviewsEnabled() || !$item->getType()->supports('reviews')) {
            return false;
        }

        if (!$this->security->isGranted(self::CAP_REVIEW_CREATE)) {
            return false;
        }

        $user = $this->security->getUser();

        if ($user instanceof User && $user->getId() === $item->getOwnerId() && !$this->config->reviewsAllowOwner()) {
            return false;
        }

        return true;
    }

    public function currentUser(): ?User
    {
        $user = $this->security->getUser();

        return $user instanceof User ? $user : null;
    }

    /**
     * Statuses the current viewer is allowed to see in a listing. Anonymous and
     * ordinary members see published entries only; the member dashboard passes
     * its own owner filter alongside this.
     *
     * @return list<string>
     */
    public function visibleStatuses(): array
    {
        if ($this->canModerate() || $this->security->isGranted(self::CAP_ITEM_VIEW_ANY)) {
            return ShowcaseItem::STATUSES;
        }

        return [ShowcaseItem::STATUS_PUBLISHED];
    }
}
