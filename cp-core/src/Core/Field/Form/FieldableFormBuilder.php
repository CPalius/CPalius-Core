<?php

declare(strict_types=1);

namespace App\Core\Field\Form;

use App\Core\Field\FieldDefinitionRegistry;
use Symfony\Component\Form\FormBuilderInterface;

/**
 * Adds the custom-field sub-form for a bundle to any host form. The host maps its
 * own columns (title, slug, status…); this bolts on everything defined in
 * /aacp/fields. No-op when the bundle has no fields.
 */
final class FieldableFormBuilder
{
    public function __construct(
        private readonly FieldDefinitionRegistry $definitions,
    ) {
    }

    /**
     * @param list<string>|null $groups only these field groups (null = all)
     */
    public function add(
        FormBuilderInterface $builder,
        string $bundle,
        string $locale,
        string $childName = 'fields',
        ?array $groups = null,
    ): void {
        if (!$this->definitions->hasFields($bundle)) {
            return;
        }

        $builder->add($childName, FieldsFormType::class, [
            'bundle' => $bundle,
            'field_locale' => $locale,
            'groups' => $groups,
            'mapped' => false,
        ]);
    }
}
