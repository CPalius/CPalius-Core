<?php

declare(strict_types=1);

namespace Modules\Pages\Form;

use App\Entity\Node;
use Modules\Pages\Form\DTO\PageFormModel;
use Modules\Pages\PageTemplate;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\DateTimeType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\UrlType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Studio PageType mapped to PageFormModel; persistence via mapDtoToNode().
 */
final class PageType extends AbstractType
{
    public function __construct(
        private readonly TranslatorInterface $translator,
    ) {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('title', TextType::class, [
                'label' => 'pages.form.field.title',
                'required' => true,
                'attr' => [
                    'class' => 'form-control text-fs-base font-medium',
                    'placeholder' => $this->translator->trans('pages.form.placeholder.title'),
                    'autofocus' => true,
                    'data-page-form-target' => 'title',
                ],
            ])
            ->add('slug', TextType::class, [
                'label' => 'pages.form.field.slug',
                'required' => false,
                'attr' => [
                    'class' => 'form-control',
                    'placeholder' => $this->translator->trans('pages.form.placeholder.slug'),
                    'data-page-form-target' => 'slug',
                ],
            ])
            ->add('excerpt', TextareaType::class, [
                'label' => 'pages.form.field.excerpt',
                'required' => false,
                'attr' => [
                    'class' => 'form-control',
                    'rows' => 2,
                    'placeholder' => $this->translator->trans('pages.form.placeholder.excerpt'),
                ],
            ])
            ->add('body', TextareaType::class, [
                'label' => 'pages.form.field.body',
                'required' => false,
                'attr' => [
                    'class' => 'form-control min-h-[400px]',
                    'rows' => 16,
                    'style' => 'min-height: 400px;',
                    'data-cpeditor' => true,
                    'placeholder' => $this->translator->trans('pages.form.placeholder.body'),
                ],
            ])
            ->add('isFeatured', CheckboxType::class, [
                'label' => 'pages.form.field.is_featured',
                'required' => false,
                'attr' => [
                    'class' => 'form-check-input h-4 w-4 text-primary-600 focus:ring-primary-600',
                ],
            ])
            ->add('status', ChoiceType::class, [
                'label' => 'pages.form.field.status',
                'choices' => [
                    'pages.form.status.draft' => Node::STATUS_DRAFT,
                    'pages.form.status.published' => Node::STATUS_PUBLISHED,
                    'pages.form.status.scheduled' => Node::STATUS_SCHEDULED,
                ],
                'expanded' => false,
                'multiple' => false,
                'attr' => [
                    'class' => 'form-control',
                    'data-page-form-target' => 'status',
                ],
            ])
            ->add('publishedAt', DateTimeType::class, [
                'label' => 'pages.form.field.published_at',
                'required' => false,
                'widget' => 'single_text',
                'html5' => true,
                'attr' => [
                    'class' => 'form-control',
                    'data-page-form-target' => 'publishedAt',
                ],
                'help' => 'pages.form.field.published_at_help',
            ])
            ->add('template', ChoiceType::class, [
                'label' => 'pages.form.field.template',
                'choices' => PageTemplate::choices(),
                'expanded' => false,
                'multiple' => false,
                'attr' => ['class' => 'form-control'],
            ])
            ->add('featuredImageAssetId', HiddenType::class, [
                'required' => false,
                'attr' => [
                    'data-target-input' => 'featured_image_asset_id',
                ],
            ])
            ->add('fieldGroupId', HiddenType::class, [
                'required' => false,
            ])
            ->add('customFieldsJson', HiddenType::class, [
                'required' => false,
                'attr' => [
                    'data-page-acf-json' => '',
                ],
            ])
            ->add('customCss', TextareaType::class, [
                'label' => 'pages.form.field.custom_css',
                'required' => false,
                'attr' => [
                    'class' => 'form-control font-mono text-fs-xs',
                    'rows' => 8,
                    'placeholder' => '.page-hero { ... }',
                    'spellcheck' => 'false',
                ],
            ])
            ->add('customJs', TextareaType::class, [
                'label' => 'pages.form.field.custom_js',
                'required' => false,
                'attr' => [
                    'class' => 'form-control font-mono text-fs-xs',
                    'rows' => 8,
                    'placeholder' => 'document.addEventListener(\'DOMContentLoaded\', () => { ... });',
                    'spellcheck' => 'false',
                ],
            ])
            ->add('seoMetaDescription', TextareaType::class, [
                'label' => 'pages.form.field.seo_meta_description',
                'required' => false,
                'attr' => [
                    'class' => 'form-control',
                    'rows' => 3,
                    'maxlength' => 160,
                    'data-seo-char-counter' => true,
                    'placeholder' => $this->translator->trans('pages.form.placeholder.seo_meta'),
                ],
            ])
            ->add('seoFocusKeyword', TextType::class, [
                'label' => 'pages.form.field.seo_focus_keyword',
                'required' => false,
                'attr' => ['class' => 'form-control'],
            ])
            ->add('seoCanonicalUrl', UrlType::class, [
                'label' => 'pages.form.field.seo_canonical_url',
                'required' => false,
                'default_protocol' => null,
                'attr' => [
                    'class' => 'form-control',
                    'placeholder' => $this->translator->trans('pages.form.placeholder.canonical'),
                ],
            ])
            ->add('seoNoindex', CheckboxType::class, [
                'label' => 'pages.form.field.seo_noindex',
                'required' => false,
                'attr' => [
                    'class' => 'form-check-input h-4 w-4 text-primary-600 focus:ring-primary-600',
                ],
            ])
        ;

        if ($options['include_auto_translate']) {
            $isEdit = (bool) $options['auto_translate_edit'];
            $builder->add('autoTranslate', CheckboxType::class, [
                'label' => $isEdit ? 'ai.form.auto_translate_edit_node' : 'ai.form.auto_translate',
                'required' => false,
                'help' => $isEdit ? 'ai.form.auto_translate_edit_help' : 'ai.form.auto_translate_help',
                'attr' => [
                    'class' => 'form-check-input h-4 w-4 text-primary-600 focus:ring-primary-600',
                ],
            ]);
        }
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => PageFormModel::class,
            'csrf_token_id' => 'admin_page_form',
            'include_auto_translate' => false,
            'auto_translate_edit' => false,
        ]);

        $resolver->setAllowedTypes('include_auto_translate', 'bool');
        $resolver->setAllowedTypes('auto_translate_edit', 'bool');
    }
}
