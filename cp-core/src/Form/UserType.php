<?php

declare(strict_types=1);

namespace App\Form;

use App\Core\Field\Form\FieldableFormBuilder;
use App\Entity\User;
use App\Form\DTO\UserFormModel;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * AACP user create/edit form for UserFormModel (PostType-style DTO mapping).
 * Role choices from RoleConfigManager; password optional on edit (controller decides).
 * Custom fields defined for the "user" bundle (/aacp/fields) bolt on as an
 * unmapped "fields" child — no-op until an admin defines one.
 */
final class UserType extends AbstractType
{
    public function __construct(
        private readonly FieldableFormBuilder $fieldableFormBuilder,
    ) {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('email', EmailType::class, [
                'label' => 'aacp.users.column.email',
                'required' => true,
                'attr' => [
                    'class' => 'form-control !border-white/10 !bg-dark-1 !text-slate-100',
                    'placeholder' => 'ornek@cpalius.com',
                    'autofocus' => true,
                ],
            ])
            ->add('username', TextType::class, [
                'label' => 'aacp.users.form.field.username',
                'required' => false,
                'attr' => [
                    'class' => 'form-control !border-white/10 !bg-dark-1 !text-slate-100',
                    'placeholder' => 'ornek_kullanici (opsiyonel)',
                ],
            ])
            ->add('plainPassword', PasswordType::class, [
                'label' => 'aacp.users.form.field.password',
                'required' => false,
                'attr' => [
                    'class' => 'form-control !border-white/10 !bg-dark-1 !text-slate-100',
                    'autocomplete' => 'new-password',
                ],
                'help' => $options['is_edit']
                    ? 'aacp.users.form.field.password_help_edit'
                    : 'aacp.users.form.field.password_help_create',
            ])
            ->add('firstName', TextType::class, [
                'label' => 'aacp.users.form.field.first_name',
                'required' => false,
                'attr' => [
                    'class' => 'form-control !border-white/10 !bg-dark-1 !text-slate-100',
                ],
            ])
            ->add('lastName', TextType::class, [
                'label' => 'aacp.users.form.field.last_name',
                'required' => false,
                'attr' => [
                    'class' => 'form-control !border-white/10 !bg-dark-1 !text-slate-100',
                ],
            ])
            ->add('status', ChoiceType::class, [
                'label' => 'aacp.users.form.field.account_status',
                'choices' => [
                    'aacp.users.form.status.active' => User::STATUS_ACTIVE,
                    'aacp.users.form.status.inactive' => User::STATUS_INACTIVE,
                    'aacp.users.form.status.banned' => User::STATUS_BANNED,
                ],
                'expanded' => false,
                'multiple' => false,
                'attr' => [
                    'class' => 'form-control !border-white/10 !bg-dark-1 !text-slate-100',
                ],
            ])
            ->add('roles', ChoiceType::class, [
                'label' => 'aacp.users.column.roles',
                'choices' => $options['role_choices'],
                'multiple' => true,
                'expanded' => true,
                'required' => false,
                'attr' => [
                    'class' => 'space-y-1.5',
                ],
                'label_attr' => [
                    'class' => 'flex items-center gap-2 text-fs-sm !text-slate-300',
                ],
            ])
        ;

        $this->fieldableFormBuilder->add($builder, 'user', (string) $options['field_locale']);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => UserFormModel::class,
            'csrf_token_id' => 'aacp_user_form',
            'role_choices' => [],
            'is_edit' => false,
            'field_locale' => 'und',
        ]);

        $resolver->setAllowedTypes('role_choices', 'array');
        $resolver->setAllowedTypes('is_edit', 'bool');
        $resolver->setAllowedTypes('field_locale', 'string');
    }
}
