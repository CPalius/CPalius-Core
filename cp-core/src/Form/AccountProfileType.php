<?php

declare(strict_types=1);

namespace App\Form;

use App\Core\Account\AccountProfileExtensionInterface;
use App\Core\Field\Form\FieldableFormBuilder;
use App\Form\DTO\AccountProfileFormModel;
use Symfony\Component\DependencyInjection\Attribute\TaggedIterator;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
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
        private readonly FieldableFormBuilder $fieldableFormBuilder,
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
            ->add('location', TextType::class, [
                'label' => 'account.profile.location',
                'required' => false,
            ])
            ->add('locale', ChoiceType::class, [
                'label' => 'account.profile.locale',
                'help' => 'account.profile.locale_help',
                'choices' => $options['locale_choices'],
                'required' => true,
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

        $this->fieldableFormBuilder->add($builder, 'user', (string) $options['field_locale']);
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
            // Filled by the controller from LocaleProvider. A default of [] keeps
            // the type constructible in isolation (form tests, a module reusing
            // it) without hard-coding a language list anywhere but the database.
            'locale_choices' => [],
            'field_locale' => 'und',
        ]);

        $resolver->setAllowedTypes('locale_choices', 'array');
        $resolver->setAllowedTypes('field_locale', 'string');
    }
}
