<?php

declare(strict_types=1);

namespace App\Core\Migrate;

/**
 * Turns what an operator typed into what a migration asked for.
 *
 * Both directions are checked. A missing required option is refused, and so is
 * an option nobody declared: `-o fiel=export.xml` would otherwise be accepted
 * in silence and the migration would run against its default, which looks
 * exactly like the import having no effect.
 */
final class MigrationOptionResolver
{
    /**
     * @param list<MigrationOption> $spec
     * @param array<string, string> $values
     *
     * @return array<string, string>
     */
    public static function resolve(array $spec, array $values): array
    {
        $declared = [];
        foreach ($spec as $option) {
            $declared[$option->name] = $option;
        }

        $unknown = array_diff(array_keys($values), array_keys($declared));

        if ($unknown !== []) {
            throw new \InvalidArgumentException(sprintf('Unknown option(s): %s. This migration accepts: %s.', implode(', ', $unknown), $declared === [] ? '(none)' : implode(', ', array_keys($declared))));
        }

        $resolved = [];
        $missing = [];

        foreach ($declared as $name => $option) {
            $value = $values[$name] ?? null;

            if ($value === null || trim($value) === '') {
                if ($option->required) {
                    $missing[] = sprintf('%s (%s)', $name, $option->description);

                    continue;
                }

                $value = $option->default ?? '';
            }

            $resolved[$name] = $value;
        }

        if ($missing !== []) {
            throw new \InvalidArgumentException(sprintf('Missing required option(s): %s.', implode('; ', $missing)));
        }

        return $resolved;
    }
}
