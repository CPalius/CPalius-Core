<?php

declare(strict_types=1);

namespace Modules\NotABundle;

/**
 * Contract-violating module fixture for Manifesto Law 2.2 — autoloadable but not a Symfony Bundle.
 * ModuleRegistry::validate() must quarantine it before Kernel can instantiate it as a bundle.
 */
final class NotABundleModule
{
    public function boot(): void
    {
        // Intentionally empty: the failure is what this class is NOT (a Bundle).
    }
}
