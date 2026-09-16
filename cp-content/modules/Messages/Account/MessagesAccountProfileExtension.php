<?php

declare(strict_types=1);

namespace Modules\Messages\Account;

use App\Core\Account\AccountProfileExtensionInterface;
use App\Entity\User;
use Modules\Messages\Service\MessagesAccess;
use Modules\Messages\Service\MessagesConfig;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormInterface;

final class MessagesAccountProfileExtension implements AccountProfileExtensionInterface
{
    public function section(): array
    {
        return [
            'id' => 'messages_privacy',
            'legendKey' => 'messages.account.section',
            'hintKey' => 'messages.account.hint',
            'fields' => ['messagesAllowFrom'],
        ];
    }

    public function buildForm(FormBuilderInterface $builder): void
    {
        $builder->add('messagesAllowFrom', ChoiceType::class, [
            'label' => 'messages.account.allow_from',
            'required' => true,
            'mapped' => false,
            'choices' => [
                'messages.privacy.everyone' => MessagesConfig::PRIVACY_EVERYONE,
                'messages.privacy.contacts' => MessagesConfig::PRIVACY_CONTACTS,
                'messages.privacy.nobody' => MessagesConfig::PRIVACY_NOBODY,
            ],
        ]);
    }

    public function valuesFromUser(User $user): array
    {
        $value = $user->getDataValue(MessagesAccess::DATA_ALLOW_FROM, MessagesConfig::PRIVACY_EVERYONE);

        return [
            'messagesAllowFrom' => \is_string($value) && \in_array($value, MessagesConfig::PRIVACY_OPTIONS, true)
                ? $value
                : MessagesConfig::PRIVACY_EVERYONE,
        ];
    }

    public function saveToUser(User $user, FormInterface $form): void
    {
        if (!$form->has('messagesAllowFrom')) {
            return;
        }

        $value = (string) $form->get('messagesAllowFrom')->getData();
        $user->setDataValue(
            MessagesAccess::DATA_ALLOW_FROM,
            \in_array($value, MessagesConfig::PRIVACY_OPTIONS, true) ? $value : MessagesConfig::PRIVACY_EVERYONE,
        );
    }
}
