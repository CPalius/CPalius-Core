<?php

declare(strict_types=1);

namespace App\Core\Module;

/**
 * Lets a core migration ask "is this module switched on?" before creating the
 * module's tables, so a site only gets the schema of the modules it runs.
 */
final class ActiveModules
{
    /**
     * @param class-string $moduleClass
     */
    public static function has(string $moduleClass): bool
    {
        $file = \dirname(__DIR__, 3).'/config/active_modules.php';

        return \in_array($moduleClass, (new ActiveModulesFileWriter($file))->read(), true);
    }
}
