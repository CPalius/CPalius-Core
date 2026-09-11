<?php

declare(strict_types=1);

namespace App\Form;

use App\Core\Account\AccountProfileExtensionInterface;
use App\Form\DTO\AccountProfileFormModel;
use Symfony\Component\DependencyInjection\Attribute\TaggedIterator;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Form\FormView;
use Symfony\Component\OptionsResolver\OptionsResolver;

final class AccountProfileType extends AbstractType
{
    /**
     * @param iterable<AccountProfileExtensionInterface> $extensions
     */
    public function __construct(
        #[TaggedIterator('cpalius.account.profile_extension')]
        private readonly iterable $extensions = [],
    ) {
    }

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
        ;

        foreach ($this->extensions as $extension) {
            $extension->buildForm($builder);
        }
    }

    public function buildView(FormView $view, FormInterface $form, array $options): void
    {
        $sections = [];
        foreach ($this->extensions as $extension) {
            $sections[] = $extension->section();
        }
        $view->vars['profile_sections'] = $sections;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => AccountProfileFormModel::class,
            'csrf_token_id' => 'account_profile_form',
        ]);
    }
}
