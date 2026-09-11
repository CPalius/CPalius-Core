<?php

declare(strict_types=1);

namespace App\Core\Resource\Admin;

use App\Core\Resource\Attribute\CpField;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Turns a resource entity's Doctrine mapping (+ optional #[CpField] overrides)
 * into an ordered list of field descriptors for the auto admin.
 *
 * Sensitive columns are refused by name regardless of attributes.
 */
final class ResourceFieldResolver
{
    private const DENY = [
        'password', 'plainpassword', 'salt', 'hash', 'passwordhash', 'secret', 'secretcipher',
        'token', 'apikey', 'rememberme', 'confirmationtoken', 'resettoken', 'tenantid', 'roles',
    ];

    private const DENY_SUFFIX = ['token', 'secret', 'hash', 'password'];

    /** Columns shown in the list but never editable. */
    private const AUTO_READONLY = ['id', 'createdat', 'updatedat', 'created_at', 'updated_at'];

    /** @var array<class-string, list<ResourceFieldDescriptor>> */
    private array $cache = [];

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * @param class-string $entityClass
     *
     * @return list<ResourceFieldDescriptor>
     */
    public function resolve(string $entityClass): array
    {
        if (isset($this->cache[$entityClass])) {
            return $this->cache[$entityClass];
        }

        $metadata = $this->entityManager->getClassMetadata($entityClass);
        $reflection = $metadata->getReflectionClass();
        $identifiers = array_flip($metadata->getIdentifierFieldNames());

        $descriptors = [];

        foreach ($metadata->getFieldNames() as $field) {
            if ($this->denied($field)) {
                continue;
            }
            $mapping = $metadata->getFieldMapping($field);
            $type = \is_object($mapping) ? (string) $mapping->type : (string) ($mapping['type'] ?? 'string');
            $enumRaw = \is_object($mapping) ? ($mapping->enumType ?? null) : ($mapping['enumType'] ?? null);
            $nullable = \is_object($mapping) ? (bool) $mapping->nullable : (bool) ($mapping['nullable'] ?? false);
            $enumType = \is_string($enumRaw) ? $enumRaw : null;
            if (\in_array($type, ['json', 'simple_array', 'array', 'object', 'blob', 'binary'], true)) {
                continue;
            }

            $attr = $this->attribute($reflection, $field);
            $autoReadonly = isset($identifiers[$field]) || \in_array(strtolower($field), self::AUTO_READONLY, true);

            $descriptors[] = new ResourceFieldDescriptor(
                property: $field,
                label: $attr?->label ?? $this->humanise($field),
                type: $type,
                nullable: $nullable,
                inList: $attr?->list ?? true,
                inForm: ($attr?->form ?? true) && !isset($identifiers[$field]),
                readonly: $attr?->readonly ?? $autoReadonly,
                sortable: $attr?->sortable ?? \in_array($type, ['integer', 'smallint', 'bigint', 'decimal', 'float', 'boolean', 'date', 'date_immutable', 'datetime', 'datetime_immutable', 'string'], true),
                searchable: $attr?->searchable ?? false,
                priority: $attr?->priority ?? ($autoReadonly ? 100 : 10),
                widget: $attr?->widget ?? $this->widgetFor($type),
                choices: $this->enumChoices($enumType),
            );
        }

        foreach ($metadata->getAssociationNames() as $field) {
            if (!$metadata->isSingleValuedAssociation($field) || $this->denied($field)) {
                continue;
            }
            $attr = $this->attribute($reflection, $field);

            $descriptors[] = new ResourceFieldDescriptor(
                property: $field,
                label: $attr?->label ?? $this->humanise($field),
                type: 'reference',
                nullable: true,
                inList: $attr?->list ?? true,
                inForm: $attr?->form ?? true,
                readonly: $attr?->readonly ?? false,
                sortable: false,
                searchable: false,
                priority: $attr?->priority ?? 20,
                widget: 'reference',
                targetClass: $metadata->getAssociationTargetClass($field),
            );
        }

        usort($descriptors, static fn (ResourceFieldDescriptor $a, ResourceFieldDescriptor $b): int => $a->priority <=> $b->priority ?: strcmp($a->property, $b->property));

        return $this->cache[$entityClass] = $descriptors;
    }

    /**
     * @param class-string $entityClass
     *
     * @return list<ResourceFieldDescriptor>
     */
    public function listColumns(string $entityClass): array
    {
        return array_values(array_filter($this->resolve($entityClass), static fn (ResourceFieldDescriptor $d): bool => $d->inList));
    }

    /**
     * @param class-string $entityClass
     *
     * @return list<ResourceFieldDescriptor>
     */
    public function formFields(string $entityClass): array
    {
        return array_values(array_filter($this->resolve($entityClass), static fn (ResourceFieldDescriptor $d): bool => $d->inForm && !$d->readonly));
    }

    private function denied(string $field): bool
    {
        $lower = strtolower(str_replace('_', '', $field));
        if (\in_array($lower, self::DENY, true)) {
            return true;
        }
        foreach (self::DENY_SUFFIX as $suffix) {
            if (str_ends_with($lower, $suffix) && $lower !== $suffix) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param \ReflectionClass<object> $reflection
     */
    private function attribute(\ReflectionClass $reflection, string $property): ?CpField
    {
        if (!$reflection->hasProperty($property)) {
            return null;
        }
        $attributes = $reflection->getProperty($property)->getAttributes(CpField::class);

        return $attributes === [] ? null : $attributes[0]->newInstance();
    }

    private function widgetFor(string $doctrineType): string
    {
        return match ($doctrineType) {
            'boolean' => 'checkbox',
            'integer', 'smallint', 'bigint' => 'number',
            'decimal', 'float' => 'number',
            'text' => 'textarea',
            'date', 'date_immutable' => 'date',
            'datetime', 'datetime_immutable' => 'datetime',
            default => 'text',
        };
    }

    /**
     * @return array<string, string>|null value => label
     */
    private function enumChoices(?string $enumClass): ?array
    {
        if ($enumClass === null || !enum_exists($enumClass)) {
            return null;
        }

        $choices = [];
        foreach ($enumClass::cases() as $case) {
            $value = $case instanceof \BackedEnum ? (string) $case->value : $case->name;
            $choices[$value] = $case->name;
        }

        return $choices;
    }

    private function humanise(string $field): string
    {
        return ucfirst(trim(preg_replace('/(?<!^)[A-Z]/', ' $0', $field) ?? $field));
    }
}
