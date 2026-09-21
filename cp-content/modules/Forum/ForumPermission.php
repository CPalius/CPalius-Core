<?php

declare(strict_types=1);

namespace Modules\Forum;

/**
 * Forum ACL dictionary. Stored as permission_key strings; this enum is the source of truth.
 * content.* is the node × group matrix. moderate.* is local-mod + super-mod.
 */
enum ForumPermission: string
{
    case View = 'view';
    case ViewOwnOnly = 'view_own_only';
    case ThreadCreate = 'thread_create';
    case Reply = 'reply';
    case EditOwn = 'edit_own';
    case DeleteOwn = 'delete_own';
    case Upload = 'upload';
    case Download = 'download';
    case PollCreate = 'poll_create';
    case PollVote = 'poll_vote';
    case Search = 'search';
    case Subscribe = 'subscribe';
    case NoApprove = 'no_approve';
    case Sticky = 'sticky';
    case LockOwn = 'lock_own';
    case Announce = 'announce';
    case IgnoreFlood = 'ignore_flood';
    case SoftDelete = 'soft_delete';

    case ModerateApprove = 'moderate.approve';
    case ModerateRestore = 'moderate.restore';
    case ModerateViewHeld = 'moderate.view_held';
    case ModerateViewDeleted = 'moderate.view_deleted';
    case ModerateViewIp = 'moderate.view_ip';
    case ModerateEdit = 'moderate.edit';
    case ModerateDelete = 'moderate.delete';
    case ModerateLock = 'moderate.lock';
    case ModerateSticky = 'moderate.sticky';
    case ModerateMove = 'moderate.move';
    case ModerateMerge = 'moderate.merge';
    case ModerateSplit = 'moderate.split';
    case ModerateBan = 'moderate.ban';
    case ModerateViewLogs = 'moderate.view_logs';

    public function isModerate(): bool
    {
        return str_starts_with($this->value, 'moderate.');
    }

    public function isContent(): bool
    {
        return !$this->isModerate();
    }

    /**
     * @return list<self>
     */
    public static function contentCases(): array
    {
        return array_values(array_filter(self::cases(), static fn (self $p): bool => $p->isContent()));
    }

    /**
     * @return list<self>
     */
    public static function moderateCases(): array
    {
        return array_values(array_filter(self::cases(), static fn (self $p): bool => $p->isModerate()));
    }

    /**
     * Default allow set for the standard_member pack (and YAML member-like roles).
     *
     * @return list<self>
     */
    public static function standardMemberGrants(): array
    {
        return [
            self::View,
            self::ThreadCreate,
            self::Reply,
            self::Upload,
            self::Download,
            self::PollCreate,
            self::PollVote,
            self::Search,
            self::Subscribe,
            self::EditOwn,
            self::DeleteOwn,
        ];
    }

    /**
     * standard_local_mod pack. moderate.ban is not included.
     *
     * @return list<self>
     */
    public static function standardLocalModGrants(): array
    {
        return [
            self::ModerateApprove,
            self::ModerateRestore,
            self::ModerateViewHeld,
            self::ModerateViewDeleted,
            self::ModerateViewIp,
            self::ModerateEdit,
            self::ModerateDelete,
            self::ModerateLock,
            self::ModerateSticky,
            self::ModerateMove,
            self::ModerateMerge,
            self::ModerateSplit,
            self::ModerateViewLogs,
        ];
    }

    /**
     * read_only pack.
     *
     * @return list<self>
     */
    public static function readOnlyGrants(): array
    {
        return [
            self::View,
            self::Download,
            self::Search,
            self::Subscribe,
        ];
    }
}
