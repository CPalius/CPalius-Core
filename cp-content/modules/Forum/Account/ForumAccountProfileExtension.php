<?php

declare(strict_types=1);

namespace Modules\Forum\Account;

use App\Core\Account\AccountProfileExtensionInterface;
use App\Entity\User;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormInterface;

final class ForumAccountProfileExtension implements AccountProfileExtensionInterface
{
    private const FIELDS = [
        'forumNotifReply' => ['key' => 'forum_notif_reply', 'label' => 'account.profile.notif_reply'],
        'forumNotifThread' => ['key' => 'forum_notif_thread', 'label' => 'account.profile.notif_thread'],
        'forumNotifQuote' => ['key' => 'forum_notif_quote', 'label' => 'account.profile.notif_quote'],
        'forumNotifReaction' => ['key' => 'forum_notif_reaction', 'label' => 'account.profile.notif_reaction'],
        'forumNotifDislike' => ['key' => 'forum_notif_dislike', 'label' => 'account.profile.notif_dislike'],
        'forumNotifMention' => ['key' => 'forum_notif_mention', 'label' => 'account.profile.notif_mention'],
        'forumNotifWatch' => ['key' => 'forum_notif_watch', 'label' => 'account.profile.notif_watch'],
    ];

    public function section(): array
    {
        return [
            'id' => 'forum_notifications',
            'legendKey' => 'account.profile.section_notifications',
            'hintKey' => 'account.profile.notifications_hint',
            'fields' => array_keys(self::FIELDS),
        ];
    }

    public function buildForm(FormBuilderInterface $builder): void
    {
        foreach (self::FIELDS as $name => $meta) {
            $builder->add($name, CheckboxType::class, [
                'label' => $meta['label'],
                'required' => false,
                'mapped' => false,
            ]);
        }
    }

    public function valuesFromUser(User $user): array
    {
        $values = [];
        foreach (self::FIELDS as $name => $meta) {
            $values[$name] = $this->prefEnabled($user, $meta['key']);
        }

        return $values;
    }

    public function saveToUser(User $user, FormInterface $form): void
    {
        foreach (self::FIELDS as $name => $meta) {
            if (!$form->has($name)) {
                continue;
            }
            $user->setDataValue($meta['key'], (bool) $form->get($name)->getData());
        }
    }

    private function prefEnabled(User $user, string $key): bool
    {
        $value = $user->getDataValue($key, true);

        return $value !== false && $value !== 0 && $value !== '0';
    }
}
