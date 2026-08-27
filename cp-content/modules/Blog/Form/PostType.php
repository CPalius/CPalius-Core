<?php

declare(strict_types=1);

namespace Modules\Blog\Form;

use App\Entity\Category;
use App\Entity\Node;
use Modules\Blog\Form\DTO\PostFormModel;
use Modules\Blog\PostSubType;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\DateTimeType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\UrlType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * PostFormModel DTO'suna maplenen Studio (Yönetim Paneli) formu — Blog
 * modülünün "post" tipi Node'ları için tek CRUD form kaynağı (create/edit
 * ikisinde de PostAdminController tarafından kullanılır).
 *
 * Bilinçli tasarım kararları:
 * - data_class PostFormModel::class'tır, Node DEĞİL: form katmanı Doctrine
 *   entity'sinden tamamen izole tutulur (Manifesto Law 3.1 hibrit model
 *   ruhu) — Node'a yazım her zaman controller'daki mapDtoToNode() üzerinden,
 *   açık ve denetlenebilir bir adımda yapılır.
 * - 'body' bilinçli olarak sanitize EDİLMEZ burada: RichTextSanitizer,
 *   controller'da mapDtoToNode() içinde çağrılır (Manifesto Law 5.3),
 *   form katmanı ham veriyi taşır.
 * - csrf_protection: PostAdminController zaten kendi CSRF token'ını
 *   ('admin_post_form') manuel doğruluyordu; form bileşenine geçişte de
 *   aynı token id'si korunur, böylece mevcut Studio form şablonundaki
 *   davranış (tek CSRF alanı) değişmez.
 * - postSubType + türe özel alanlar: her biri 'data-post-sub-type-block'
 *   attribute'u taşır (hangi türde görünür olacağı) — cp-core/assets/
 *   admin-post-form.js bu attribute'u okuyup JS ile show/hide yapar.
 *   Sunucu tarafında form seviyesinde koşullu "required" YOKTUR (hepsi
 *   required: false) çünkü zorunluluk PostFormModel::validatePostSubTypeFields()
 *   içinde çalışma zamanı türüne göre değerlendirilir (bkz. o sınıfın
 *   #[Assert\Callback] doküman notu).
 */
final class PostType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('postSubType', ChoiceType::class, [
                'label' => 'blog.posts.form.field.post_sub_type',
                'choices' => PostSubType::choices(),
                'expanded' => false,
                'multiple' => false,
                'attr' => [
                    'class' => 'form-control',
                    'data-post-form-target' => 'postSubType',
                ],
            ])
            ->add('title', TextType::class, [
                'label' => 'blog.posts.form.field.title',
                'required' => true,
                'attr' => [
                    'class' => 'form-control text-fs-base font-medium',
                    'placeholder' => 'Yazı başlığı',
                    'autofocus' => true,
                    'data-post-form-target' => 'title',
                ],
            ])
            ->add('slug', TextType::class, [
                'label' => 'blog.posts.form.field.slug',
                'required' => false,
                'attr' => [
                    'class' => 'form-control',
                    'placeholder' => 'baslik-otomatik-uretilir',
                    'data-post-form-target' => 'slug',
                ],
            ])
            ->add('excerpt', TextareaType::class, [
                'label' => 'blog.posts.form.field.excerpt',
                'required' => false,
                'attr' => [
                    'class' => 'form-control',
                    'rows' => 2,
                    'placeholder' => 'Blog listesinde gösterilecek kısa özet...',
                ],
            ])
            ->add('body', TextareaType::class, [
                'label' => 'blog.posts.form.field.body',
                'required' => true,
                'attr' => [
                    'class' => 'form-control min-h-[550px]',
                    'rows' => 20,
                    'style' => 'min-height: 550px;',
                    'data-cpeditor' => true,
                    'placeholder' => 'Yazı içeriği...',
                ],
            ])
            ->add('isFeatured', CheckboxType::class, [
                'label' => 'blog.posts.form.field.is_featured',
                'required' => false,
                'attr' => [
                    'class' => 'form-check-input h-4 w-4 text-primary-600 focus:ring-primary-600',
                ],
            ])
            ->add('status', ChoiceType::class, [
                'label' => 'blog.posts.form.field.status',
                'choices' => [
                    'blog.posts.form.status.draft' => Node::STATUS_DRAFT,
                    'blog.posts.form.status.published' => Node::STATUS_PUBLISHED,
                    'blog.posts.form.status.scheduled' => Node::STATUS_SCHEDULED,
                ],
                'expanded' => false,
                'multiple' => false,
                'attr' => [
                    'class' => 'form-control',
                    'data-post-form-target' => 'status',
                ],
            ])
            ->add('publishedAt', DateTimeType::class, [
                'label' => 'blog.posts.form.field.published_at',
                'required' => false,
                'widget' => 'single_text',
                'html5' => true,
                'attr' => [
                    'class' => 'form-control',
                    'data-post-form-target' => 'publishedAt',
                ],
                'help' => 'blog.posts.form.field.published_at_help',
            ])
            ->add('categoryIds', EntityType::class, [
                'label' => 'blog.categories.header',
                'class' => Category::class,
                'choice_label' => 'name',
                'choice_value' => 'id',
                'multiple' => true,
                'expanded' => true,
                'required' => false,
                // Repository ve locale filtresi PostAdminController tarafından
                // options içinde inject edilir (bkz. build() options 'category_choices').
                'choices' => $options['category_choices'] ?? [],
                'attr' => [
                    'class' => 'space-y-1.5',
                ],
                'label_attr' => [
                    'class' => 'flex items-center gap-2 text-fs-sm text-neutral-700',
                ],
            ])
            ->add('tags', TextType::class, [
                'label' => 'blog.tags.header',
                'required' => false,
                'attr' => [
                    'class' => 'form-control',
                    'placeholder' => 'php, symfony, cms',
                ],
            ])
            ->add('featuredImageAssetId', HiddenType::class, [
                'required' => false,
                'attr' => [
                    'data-target-input' => 'featured_image_asset_id',
                ],
            ])
            ->add('seoMetaDescription', TextareaType::class, [
                'label' => 'blog.posts.form.field.seo_meta_description',
                'required' => false,
                'attr' => [
                    'class' => 'form-control',
                    'rows' => 3,
                    'maxlength' => 160,
                    'data-seo-char-counter' => true,
                    'placeholder' => 'Arama sonuçlarında görünecek kısa açıklama (160 karakter)',
                ],
            ])
            ->add('seoFocusKeyword', TextType::class, [
                'label' => 'blog.posts.form.field.seo_focus_keyword',
                'required' => false,
                'attr' => [
                    'class' => 'form-control',
                ],
            ])
            ->add('seoCanonicalUrl', UrlType::class, [
                'label' => 'blog.posts.form.field.seo_canonical_url',
                'required' => false,
                'default_protocol' => null,
                'attr' => [
                    'class' => 'form-control',
                    'placeholder' => 'https://... (opsiyonel)',
                ],
            ])
            ->add('seoNoindex', CheckboxType::class, [
                'label' => 'blog.posts.form.field.seo_noindex',
                'required' => false,
                'attr' => [
                    'class' => 'form-check-input h-4 w-4 text-primary-600 focus:ring-primary-600',
                ],
            ])
            ->add('projectRepoUrl', UrlType::class, [
                'label' => 'blog.posts.form.field.project_repo_url',
                'required' => false,
                'default_protocol' => null,
                'attr' => [
                    'class' => 'form-control',
                    'placeholder' => 'https://github.com/kullanici/proje',
                    'data-post-sub-type-block' => PostSubType::PROJECT,
                ],
            ])
            ->add('projectDemoUrl', UrlType::class, [
                'label' => 'blog.posts.form.field.project_demo_url',
                'required' => false,
                'default_protocol' => null,
                'attr' => [
                    'class' => 'form-control',
                    'placeholder' => 'https://demo.proje.com',
                    'data-post-sub-type-block' => PostSubType::PROJECT,
                ],
            ])
            ->add('softwareVersion', TextType::class, [
                'label' => 'blog.posts.form.field.software_version',
                'required' => false,
                'attr' => [
                    'class' => 'form-control',
                    'placeholder' => 'ör. 2.4.1',
                    'data-post-sub-type-block' => PostSubType::SOFTWARE,
                ],
            ])
            ->add('softwareDownloadUrl', UrlType::class, [
                'label' => 'blog.posts.form.field.software_download_url',
                'required' => false,
                'default_protocol' => null,
                'attr' => [
                    'class' => 'form-control',
                    'placeholder' => 'https://...',
                    'data-post-sub-type-block' => PostSubType::SOFTWARE,
                ],
            ])
            ->add('noteCodeSnippet', TextareaType::class, [
                'label' => 'blog.posts.form.field.note_code_snippet',
                'required' => false,
                'attr' => [
                    'class' => 'form-control',
                    'rows' => 8,
                    'style' => 'font-family: monospace;',
                    'placeholder' => 'Paylaşmak istediğiniz kodu buraya yapıştırın (düz metin, HTML yorumlanmaz)...',
                    'data-post-sub-type-block' => PostSubType::NOTE,
                ],
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => PostFormModel::class,
            'csrf_token_id' => 'admin_post_form',
            'category_choices' => [],
        ]);

        $resolver->setAllowedTypes('category_choices', 'array');
    }
}
