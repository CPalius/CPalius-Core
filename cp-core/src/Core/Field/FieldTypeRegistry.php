<?php

declare(strict_types=1);

namespace App\Core\Field;

use Psr\Container\ContainerInterface;

/**
 * Compile-time collected #[CpFieldType] services, addressed by id.
 * Filled by FieldTypeRegistrationPass; no runtime scanning.
 */
final class FieldTypeRegistry
{
    /**
     * @param ContainerInterface    $locator       lazy locator for field-type services
     * @param array<string, string> $serviceByType type id => service id
     */
    public function __construct(
        private readonly ContainerInterface $locator,
        private readonly array $serviceByType,
    ) {
    }

    public function has(string $id): bool
    {
        return isset($this->serviceByType[$id]);
    }

    public function get(string $id): FieldTypeInterface
    {
        if (!isset($this->serviceByType[$id])) {
            throw new \InvalidArgumentException(sprintf('Unknown field type "%s".', $id));
        }

        $type = $this->locator->get($this->serviceByType[$id]);
        if (!$type instanceof FieldTypeInterface) {
            throw new \LogicException(sprintf('Service for field type "%s" is not a FieldTypeInterface.', $id));
        }

        return $type;
    }

    /**
     * @return list<string>
     */
    public function ids(): array
    {
        $ids = array_keys($this->serviceByType);
        sort($ids);

        return $ids;
    }

    /**
     * id => label translation key, for the AACP field form.
     *
     * @return array<string, string>
     */
    public function choices(): array
    {
        $out = [];
        foreach ($this->ids() as $id) {
            $out[$id] = $this->get($id)->label();
        }

        return $out;
    }
}
