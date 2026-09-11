<?php

declare(strict_types=1);

namespace Modules\Pages\Form;

use Modules\Pages\Form\DTO\FieldGroupFormModel;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Contracts\Translation\TranslatorInterface;

final class FieldGroupType extends AbstractType
{
    public function __construct(
        private readonly TranslatorInterface $translator,
    ) {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('title', TextType::class, [
                'label' => 'pages.field_groups.form.field.title',
                'required' => true,
                'attr' => [
                    'class' => 'form-control',
                    'autofocus' => true,
                    'data-page-form-target' => 'title',
                ],
            ])
            ->add('slug', TextType::class, [
                'label' => 'pages.field_groups.form.field.slug',
                'required' => false,
                'attr' => [
                    'class' => 'form-control',
                    'data-page-form-target' => 'slug',
                    'placeholder' => $this->translator->trans('pages.field_groups.form.placeholder.slug'),
                ],
            ])
            ->add('fieldsJson', HiddenType::class, [
                'required' => false,
                'attr' => [
                    'data-page-acf-json' => '',
                ],
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => FieldGroupFormModel::class,
            'csrf_token_id' => 'admin_page_field_group_form',
        ]);
    }
}
