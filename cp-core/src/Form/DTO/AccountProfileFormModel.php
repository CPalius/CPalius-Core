<?php

declare(strict_types=1);

namespace App\Form\DTO;

use App\Entity\User;

/**
 * Site geneli hesap profili — avatar, bildirim tercihleri, temel bilgiler.
 */
final class AccountProfileFormModel
{
    public string $email = '';
    public ?string $username = null;
    public string $firstName = '';
    public string $lastName = '';
    public ?string $currentPassword = null;
    public ?string $newPassword = null;

    public bool $forumNotifReply = true;
    public bool $forumNotifThread = true;
    public bool $forumNotifQuote = true;
    public bool $forumNotifReaction = true;
    public bool $forumNotifDislike = true;
    public bool $forumNotifMention = true;

    public static function fromUser(User $user): self
    {
        $dto = new self();
        $dto->email = $user->getEmail();
        $dto->username = $user->getUsername();
        $dto->firstName = $user->getFirstName();
        $dto->lastName = $user->getLastName();
        $dto->forumNotifReply = self::prefEnabled($user, 'forum_notif_reply');
        $dto->forumNotifThread = self::prefEnabled($user, 'forum_notif_thread');
        $dto->forumNotifQuote = self::prefEnabled($user, 'forum_notif_quote');
        $dto->forumNotifReaction = self::prefEnabled($user, 'forum_notif_reaction');
        $dto->forumNotifDislike = self::prefEnabled($user, 'forum_notif_dislike');
        $dto->forumNotifMention = self::prefEnabled($user, 'forum_notif_mention');

        return $dto;
    }

    private static function prefEnabled(User $user, string $key): bool
    {
        $value = $user->getDataValue($key, true);

        return $value !== false && $value !== 0 && $value !== '0';
    }
}
