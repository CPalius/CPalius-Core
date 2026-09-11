<?php

declare(strict_types=1);

namespace Modules\HookFixture;

use Symfony\Component\HttpKernel\Bundle\Bundle;

/**
 * Fixture module for T1.5 end-to-end tests: a real #[CpHook] service, wired
 * through the real compiled container, reacting to entity lifecycle hook
 * points fired by EntityLifecycleListener.
 */
final class HookFixtureModule extends Bundle
{
}
