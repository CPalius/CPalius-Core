<?php

declare(strict_types=1);

namespace App\Core\Field\Attribute;

/**
 * Marks a service as a CPalius field type. The type id comes from the class's
 * static id() method; collected at compile time into FieldTypeRegistry
 * (see FieldTypeRegistrationPass). A module type that fails to load drops only
 * that module's types — never the core set.
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final class CpFieldType
{
}
