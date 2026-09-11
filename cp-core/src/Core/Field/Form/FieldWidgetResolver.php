<?php

declare(strict_types=1);

namespace App\Core\Field\Form;

use App\Core\Field\Entity\FieldDefinition;
use App\Core\Field\FieldContext;
use App\Core\Field\FieldTypeRegistry;
use App\Core\Field\Type\SelectFieldType;
use App\Core\TextFormat\TextFormatAccess;
use App\Core\TextFormat\TextFormatRegistry;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\DateTimeType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\UrlType;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Maps a field definition to the Symfony form type + options used to edit ONE
 * value of it. Multi-value wrapping (CollectionType) is FieldsFormType's job.
 */
final class FieldWidgetResolver
{
    public function __construct(
        private readonly FieldTypeRegistry $types,
        private readonly TextFormatAccess $textFormats,
        private readonly TextFormatRegistry $formatCatalog,
        private readonly TranslatorInterface $translator,
    ) {
    }

    /**
     * @return array{type: class-string, options: array<string, mixed>}
     */
    public function resolve(FieldDefinition $definition, string $locale): array
    {
        $name = $definition->getName();
        $base = [
            'required' => false,
            'label' => $definition->getLabel(),
            'help' => $definition->getHelp(),
        ];

        return match ($definition->getType()) {
            'textarea' => ['type' => TextareaType::class, 'options' => $base + ['attr' => ['rows' => 4, 'class' => 'form-control']]],
            'rich_text' => ['type' => RichTextWithFormatType::class, 'options' => $base + $this->richTextOptions($definition)],
            'boolean' => ['type' => CheckboxType::class, 'options' => $base + ['false_values' => ['', '0', 'false', 'off', 'no']]],
            'integer' => ['type' => IntegerType::class, 'options' => $base + ['attr' => ['class' => 'form-control']]],
            'decimal' => ['type' => NumberType::class, 'options' => $base + ['scale' => (int) $definition->getSetting('scale', 2), 'attr' => ['class' => 'form-control']]],
            'email' => ['type' => EmailType::class, 'options' => $base + ['attr' => ['class' => 'form-control']]],
            'url' => ['type' => UrlType::class, 'options' => $base + ['default_protocol' => null, 'attr' => ['class' => 'form-control']]],
            'date' => ['type' => DateType::class, 'options' => $base + ['widget' => 'single_text', 'html5' => true, 'input' => 'string', 'attr' => ['class' => 'form-control']]],
            'datetime' => ['type' => DateTimeType::class, 'options' => $base + ['widget' => 'single_text', 'html5' => true, 'input' => 'string', 'attr' => ['class' => 'form-control']]],
            'select' => ['type' => ChoiceType::class, 'options' => $base + ['choices' => $this->selectChoices($definition, $locale), 'placeholder' => '—', 'attr' => ['class' => 'form-control']]],
            'reference' => ['type' => IntegerType::class, 'options' => $base + ['attr' => ['class' => 'form-control', 'data-field-reference' => (string) $definition->getSetting('target', 'node')]]],
            'image' => ['type' => HiddenType::class, 'options' => $base + ['attr' => ['data-field-media' => 'image', 'data-target-input' => 'field_'.$name]]],
            'file' => ['type' => HiddenType::class, 'options' => $base + ['attr' => ['data-field-media' => 'file', 'data-target-input' => 'field_'.$name]]],
            default => ['type' => TextType::class, 'options' => $base + ['attr' => ['class' => 'form-control', 'maxlength' => (int) $definition->getSetting('max_length', 255)]]],
        };
    }

    /**
     * @return array<string, string> label => value (Symfony ChoiceType order)
     */
    private function selectChoices(FieldDefinition $definition, string $locale): array
    {
        $type = $this->types->get('select');
        if (!$type instanceof SelectFieldType) {
            return [];
        }

        $out = [];
        foreach ($type->choicesFor(new FieldContext($definition, $locale)) as $value => $label) {
            $out[$label] = $value;
        }

        return $out;
    }

    /**
     * @return array<string, mixed>
     */
    private function richTextOptions(FieldDefinition $definition): array
    {
        $allowlist = \App\Core\Field\Type\RichTextFieldType::parseAllowlist($definition->getSetting('allowed_formats', ''));
        $fallback = (string) $definition->getSetting('default_format', TextFormatRegistry::BASIC_HTML);
        $usable = $this->textFormats->usableChoices($allowlist);
        if ($usable === []) {
            $usable = [$fallback => $this->formatCatalog->getOrFallback($fallback)->label];
        }

        $choices = [];
        foreach ($usable as $id => $labelKey) {
            $choices[$this->translator->trans($labelKey)] = $id;
        }

        $default = $this->textFormats->resolve($fallback, $fallback, $allowlist);
        $wysiwyg = $this->formatCatalog->getOrFallback($default)->wysiwyg;

        return [
            'format_choices' => $choices,
            'default_format' => $default,
            'wysiwyg' => $wysiwyg,
        ];
    }
}
