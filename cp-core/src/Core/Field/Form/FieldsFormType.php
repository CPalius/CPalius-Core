<?php

declare(strict_types=1);

namespace App\Core\Field\Form;

use App\Core\Field\Entity\FieldDefinition;
use App\Core\Field\FieldDefinitionRegistry;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CollectionType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * A compound sub-form holding every custom field defined for a bundle. Bound to a
 * plain array (fieldName => value). Multi-value fields become a CollectionType;
 * fields the current user cannot edit are silently omitted.
 */
final class FieldsFormType extends AbstractType
{
    public function __construct(
        private readonly FieldDefinitionRegistry $definitions,
        private readonly FieldWidgetResolver $widgets,
        private readonly Security $security,
    ) {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $locale = (string) $options['field_locale'];
        $groups = $options['groups'];

        foreach ($this->definitions->getFieldsForBundle((string) $options['bundle']) as $definition) {
            if ($groups !== null && !\in_array($definition->getFieldGroup(), $groups, true)) {
                continue;
            }
            if (!$this->canEdit($definition)) {
                continue;
            }

            $widget = $this->widgets->resolve($definition, $locale);

            if ($definition->isMultiValue()) {
                $builder->add($definition->getName(), CollectionType::class, [
                    'label' => $definition->getLabel(),
                    'help' => $definition->getHelp(),
                    'required' => false,
                    'entry_type' => $widget['type'],
                    'entry_options' => ['label' => false] + $widget['options'],
                    'allow_add' => true,
                    'allow_delete' => true,
                    'prototype' => true,
                    'by_reference' => false,
                ]);

                continue;
            }

            $builder->add($definition->getName(), $widget['type'], $widget['options']);
        }
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'bundle' => '',
            'field_locale' => 'en',
            'groups' => null,
            'data_class' => null,
            'label' => false,
            'required' => false,
        ]);
        $resolver->setAllowedTypes('bundle', 'string');
        $resolver->setAllowedTypes('field_locale', 'string');
        $resolver->setAllowedTypes('groups', ['null', 'array']);
    }

    private function canEdit(FieldDefinition $definition): bool
    {
        $capability = $definition->getEditCapability();

        return $capability === null || $this->security->isGranted($capability);
    }
}
