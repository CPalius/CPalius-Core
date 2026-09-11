<?php

declare(strict_types=1);

namespace App\Core\Account;

use App\Core\Notification\NotificationPreferenceResolver;
use App\Entity\User;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormInterface;

/**
 * Core notification preferences (editorial + mail mode). Forum keeps its own extension.
 */
final class NotificationPreferenceExtension implements AccountProfileExtensionInterface
{
    public function section(): array
    {
        return [
            'id' => 'core_notifications',
            'legendKey' => 'account.profile.section_core_notifications',
            'hintKey' => 'account.profile.core_notifications_hint',
            'fields' => ['notifContentWorkflow', 'notifMailEnabled', 'notifMailMode'],
        ];
    }

    public function buildForm(FormBuilderInterface $builder): void
    {
        $builder->add('notifContentWorkflow', CheckboxType::class, [
            'label' => 'notification.type.content_workflow',
            'required' => false,
            'mapped' => false,
        ]);
        $builder->add('notifMailEnabled', CheckboxType::class, [
            'label' => 'account.profile.notif_mail_enabled',
            'required' => false,
            'mapped' => false,
        ]);
        $builder->add('notifMailMode', ChoiceType::class, [
            'label' => 'account.profile.notif_mail_mode',
            'required' => true,
            'mapped' => false,
            'choices' => [
                'account.profile.notif_mail_instant' => NotificationPreferenceResolver::MODE_INSTANT,
                'account.profile.notif_mail_daily' => NotificationPreferenceResolver::MODE_DAILY,
                'account.profile.notif_mail_weekly' => NotificationPreferenceResolver::MODE_WEEKLY,
            ],
        ]);
    }

    public function valuesFromUser(User $user): array
    {
        return [
            'notifContentWorkflow' => $this->prefOn($user, 'notif_content_workflow'),
            'notifMailEnabled' => $this->prefOn($user, NotificationPreferenceResolver::PREF_MAIL_ENABLED),
            'notifMailMode' => (string) $user->getDataValue(
                NotificationPreferenceResolver::PREF_MAIL_MODE,
                NotificationPreferenceResolver::MODE_INSTANT,
            ),
        ];
    }

    public function saveToUser(User $user, FormInterface $form): void
    {
        if ($form->has('notifContentWorkflow')) {
            $user->setDataValue('notif_content_workflow', (bool) $form->get('notifContentWorkflow')->getData());
        }
        if ($form->has('notifMailEnabled')) {
            $user->setDataValue(
                NotificationPreferenceResolver::PREF_MAIL_ENABLED,
                (bool) $form->get('notifMailEnabled')->getData(),
            );
        }
        if ($form->has('notifMailMode')) {
            $mode = (string) $form->get('notifMailMode')->getData();
            if (\in_array($mode, [
                NotificationPreferenceResolver::MODE_INSTANT,
                NotificationPreferenceResolver::MODE_DAILY,
                NotificationPreferenceResolver::MODE_WEEKLY,
            ], true)) {
                $user->setDataValue(NotificationPreferenceResolver::PREF_MAIL_MODE, $mode);
            }
        }
    }

    private function prefOn(User $user, string $key): bool
    {
        $value = $user->getDataValue($key, true);

        return $value !== false && $value !== 0 && $value !== '0';
    }
}
