<?php

declare(strict_types=1);

namespace App\Core\Field\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\CallbackTransformer;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Compound rich_text widget: body + named text format. Accepts a legacy
 * plain string (pre-T2.5) and always submits `{value, format}`.
 */
final class RichTextWithFormatType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $attr = ['rows' => 12, 'class' => 'form-control'];
        if ($options['wysiwyg'] === true) {
            $attr['data-cpeditor'] = true;
        }

        $builder
            ->add('value', TextareaType::class, [
                'label' => false,
                'required' => false,
                'attr' => $attr,
            ])
            ->add('format', ChoiceType::class, [
                'label' => 'text_format.field.selector',
                'required' => true,
                'choices' => $options['format_choices'],
                'attr' => ['class' => 'form-select'],
            ]);

        $defaultFormat = (string) $options['default_format'];
        $builder->addModelTransformer(new CallbackTransformer(
            static function (mixed $value) use ($defaultFormat): array {
                if (\is_array($value)) {
                    return [
                        'value' => (string) ($value['value'] ?? ''),
                        'format' => (string) ($value['format'] ?? $defaultFormat),
                    ];
                }

                return [
                    'value' => \is_string($value) ? $value : '',
                    'format' => $defaultFormat,
                ];
            },
            static function (mixed $value) use ($defaultFormat): array {
                if (!\is_array($value)) {
                    return ['value' => '', 'format' => $defaultFormat];
                }

                return [
                    'value' => (string) ($value['value'] ?? ''),
                    'format' => (string) ($value['format'] ?? $defaultFormat),
                ];
            },
        ));
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'compound' => true,
            'data_class' => null,
            'format_choices' => [],
            'default_format' => 'basic_html',
            'wysiwyg' => true,
        ]);
        $resolver->setAllowedTypes('format_choices', 'array');
        $resolver->setAllowedTypes('default_format', 'string');
        $resolver->setAllowedTypes('wysiwyg', 'bool');
    }
}
