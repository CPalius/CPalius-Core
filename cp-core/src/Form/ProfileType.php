<?php

declare(strict_types=1);

namespace App\Form;

use App\Form\DTO\ProfileFormModel;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * AACP profile form mapped to ProfileFormModel (no status/roles fields).
 * Bio uses data-cpeditor; avatar uses CPaliusMediaPicker like blog featured image.
 */
final class ProfileType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('email', EmailType::class, [
                'label' => 'aacp.users.column.email',
                'required' => true,
                'attr' => [
                    'class' => 'form-control !border-white/10 !bg-dark-1 !text-slate-100',
                ],
            ])
            ->add('username', TextType::class, [
                'label' => 'aacp.users.form.username',
                'required' => false,
                'attr' => [
                    'class' => 'form-control !border-white/10 !bg-dark-1 !text-slate-100',
                ],
            ])
            ->add('firstName', TextType::class, [
                'label' => 'aacp.users.form.first_name',
                'required' => false,
                'attr' => [
                    'class' => 'form-control !border-white/10 !bg-dark-1 !text-slate-100',
                ],
            ])
            ->add('lastName', TextType::class, [
                'label' => 'aacp.users.form.last_name',
                'required' => false,
                'attr' => [
                    'class' => 'form-control !border-white/10 !bg-dark-1 !text-slate-100',
                ],
            ])
            ->add('bio', TextareaType::class, [
                'label' => 'aacp.users.profile.bio_field',
                'required' => false,
                'attr' => [
                    'class' => 'form-control !border-white/10 !bg-dark-1 !text-slate-100 min-h-[300px]',
                    'rows' => 12,
                    'style' => 'min-height: 300px;',
                    'data-cpeditor' => true,
                    'placeholder' => 'aacp.users.profile.bio_placeholder',
                ],
            ])
            ->add('avatarAssetId', HiddenType::class, [
                'required' => false,
                'attr' => [
                    'data-target-input' => 'avatar_asset_id',
                ],
            ])
            ->add('currentPassword', PasswordType::class, [
                'label' => 'aacp.users.profile.current_password',
                'required' => false,
                'mapped' => true,
                'attr' => [
                    'class' => 'form-control !border-white/10 !bg-dark-1 !text-slate-100',
                    'autocomplete' => 'current-password',
                ],
                'help' => 'aacp.users.profile.current_password_help',
            ])
            ->add('newPassword', PasswordType::class, [
                'label' => 'aacp.users.profile.new_password',
                'required' => false,
                'attr' => [
                    'class' => 'form-control !border-white/10 !bg-dark-1 !text-slate-100',
                    'autocomplete' => 'new-password',
                ],
                'help' => 'aacp.users.profile.new_password_help',
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => ProfileFormModel::class,
            'csrf_token_id' => 'aacp_profile_form',
        ]);
    }
}
