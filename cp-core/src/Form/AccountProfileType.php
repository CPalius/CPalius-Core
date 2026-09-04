<?php

declare(strict_types=1);

namespace App\Form;

use App\Form\DTO\AccountProfileFormModel;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

final class AccountProfileType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('email', EmailType::class, [
                'label' => 'account.profile.email',
            ])
            ->add('username', TextType::class, [
                'label' => 'account.profile.username',
                'required' => false,
            ])
            ->add('firstName', TextType::class, [
                'label' => 'account.profile.first_name',
                'required' => false,
            ])
            ->add('lastName', TextType::class, [
                'label' => 'account.profile.last_name',
                'required' => false,
            ])
            ->add('currentPassword', PasswordType::class, [
                'label' => 'account.profile.current_password',
                'required' => false,
                'mapped' => true,
                'attr' => ['autocomplete' => 'current-password'],
            ])
            ->add('newPassword', PasswordType::class, [
                'label' => 'account.profile.new_password',
                'required' => false,
                'attr' => ['autocomplete' => 'new-password'],
            ])
            ->add('forumNotifReply', CheckboxType::class, [
                'label' => 'account.profile.notif_reply',
                'required' => false,
            ])
            ->add('forumNotifThread', CheckboxType::class, [
                'label' => 'account.profile.notif_thread',
                'required' => false,
            ])
            ->add('forumNotifQuote', CheckboxType::class, [
                'label' => 'account.profile.notif_quote',
                'required' => false,
            ])
            ->add('forumNotifReaction', CheckboxType::class, [
                'label' => 'account.profile.notif_reaction',
                'required' => false,
            ])
            ->add('forumNotifDislike', CheckboxType::class, [
                'label' => 'account.profile.notif_dislike',
                'required' => false,
            ])
            ->add('forumNotifMention', CheckboxType::class, [
                'label' => 'account.profile.notif_mention',
                'required' => false,
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => AccountProfileFormModel::class,
            'csrf_token_id' => 'account_profile_form',
        ]);
    }
}
