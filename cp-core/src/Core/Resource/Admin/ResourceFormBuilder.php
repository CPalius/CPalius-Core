<?php

declare(strict_types=1);

namespace App\Core\Resource\Admin;

use App\Core\Resource\ResourceDefinition;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\DateTimeType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\FormType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\UrlType;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormInterface;

/**
 * Builds a Symfony form from the resolved form fields of a resource entity.
 * Only allowlisted, non-readonly properties are ever added — mass assignment is
 * bounded by the form definition (Manifesto Law 5.3).
 */
final class ResourceFormBuilder
{
    public function __construct(
        private readonly FormFactoryInterface $forms,
        private readonly ResourceFieldResolver $resolver,
    ) {
    }

    public function build(object $entity, ResourceDefinition $definition): FormInterface
    {
        $builder = $this->forms->createBuilder(FormType::class, $entity, [
            'data_class' => $definition->entityClass,
            'csrf_token_id' => 'resource_'.$definition->name,
        ]);

        foreach ($this->resolver->formFields($definition->entityClass) as $field) {
            [$type, $options] = $this->widget($field);
            $builder->add($field->property, $type, $options + [
                'label' => $field->label,
                'required' => !$field->nullable && $field->type !== 'boolean',
            ]);
        }

        return $builder->getForm();
    }

    /**
     * @return array{0: class-string, 1: array<string, mixed>}
     */
    private function widget(ResourceFieldDescriptor $field): array
    {
        if ($field->isReference() && $field->targetClass !== null) {
            return [EntityType::class, [
                'class' => $field->targetClass,
                'choice_label' => $this->choiceLabel(...),
                'placeholder' => $field->nullable ? '—' : false,
            ]];
        }

        if ($field->choices !== null) {
            return [ChoiceType::class, ['choices' => array_flip($field->choices), 'placeholder' => $field->nullable ? '—' : false]];
        }

        return match ($field->widget) {
            'checkbox' => [CheckboxType::class, ['false_values' => ['', '0', 'false', 'off']]],
            'number' => \in_array($field->type, ['integer', 'smallint', 'bigint'], true)
                ? [IntegerType::class, []]
                : [NumberType::class, ['scale' => 2]],
            // Five rows was a placeholder from when no resource existed. A
            // text column holds prose, and editing prose through a letterbox
            // is why the generic screens looked unusable on first contact.
            'textarea' => [TextareaType::class, ['attr' => ['rows' => 10, 'class' => 'font-mono']]],
            // Opt-in per property via #[CpField(widget: 'richtext')]. The
            // marker is all the server does: the toggle between CKEditor and
            // raw HTML is the operator's, because CKEditor normalises markup
            // it does not recognise and a hand-written document must be able
            // to refuse that.
            'richtext' => [TextareaType::class, ['attr' => [
                'rows' => 22,
                'class' => 'font-mono',
                'data-cp-richtext' => '',
            ]]],
            'email' => [EmailType::class, []],
            'url' => [UrlType::class, ['default_protocol' => null]],
            'date' => [DateType::class, ['widget' => 'single_text', 'html5' => true]],
            'datetime' => [DateTimeType::class, ['widget' => 'single_text', 'html5' => true]],
            default => [TextType::class, []],
        };
    }

    private function choiceLabel(object $entity): string
    {
        if (method_exists($entity, '__toString')) {
            return (string) $entity;
        }
        foreach (['getName', 'getTitle', 'getLabel', 'getEmail', 'getUsername'] as $getter) {
            if (method_exists($entity, $getter)) {
                return (string) $entity->{$getter}();
            }
        }

        return method_exists($entity, 'getId') ? '#'.$entity->getId() : $entity::class;
    }
}
