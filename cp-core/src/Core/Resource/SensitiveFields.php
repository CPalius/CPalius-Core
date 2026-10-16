<?php

declare(strict_types=1);

namespace App\Core\Resource;

use App\Core\Database\Type\EncryptedStringType;
use App\Core\Resource\Attribute\CpField;
use Doctrine\ORM\Mapping\Column;

/**
 * Which properties of an entity are marked #[CpField(sensitive: true)].
 *
 * One place answers it for the audit log, the resource API and the CSV export, so a column marked
 * sensitive is held back everywhere the same way. Reflected once per class and remembered: the audit
 * listener runs inside every flush and the API asks for every row of a list.
 */
final class SensitiveFields
{
    /** @var array<class-string, array<string, true>> */
    private static array $cache = [];

    /**
     * Walks up the class chain, because a lazy-loading proxy is a subclass of the real entity and an
     * entity may inherit its fields.
     *
     * @param class-string $class
     *
     * @return array<string, true> property name => true
     */
    public static function of(string $class): array
    {
        if (isset(self::$cache[$class])) {
            return self::$cache[$class];
        }

        $names = [];

        for ($reflection = new \ReflectionClass($class); $reflection !== false; $reflection = $reflection->getParentClass()) {
            foreach ($reflection->getProperties() as $property) {
                if ($property->getDeclaringClass()->getName() !== $reflection->getName()) {
                    continue;
                }

                foreach ($property->getAttributes(CpField::class) as $attribute) {
                    if ($attribute->newInstance()->sensitive) {
                        $names[$property->getName()] = true;
                    }
                }

                // A column stored encrypted is sensitive by definition: it must not reappear in clear text in the audit
                // log, an API response or an export just because nobody remembered to flag it as well.
                foreach ($property->getAttributes(Column::class) as $attribute) {
                    if ($attribute->newInstance()->type === EncryptedStringType::NAME) {
                        $names[$property->getName()] = true;
                    }
                }
            }
        }

        return self::$cache[$class] = $names;
    }
}
