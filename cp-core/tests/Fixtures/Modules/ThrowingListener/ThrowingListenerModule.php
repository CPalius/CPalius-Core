<?php

declare(strict_types=1);

namespace Modules\ThrowingListener;

use Symfony\Component\HttpKernel\Bundle\Bundle;

/**
 * User-space module fixture whose kernel.request subscriber always throws —
 * proof for ModuleEventListenerGuardPass (Law 2.1/2.3 extended to raw
 * Symfony listeners, not just #[CpHook]). A module wiring itself directly
 * onto the dispatcher must not be able to 500 every page.
 */
final class ThrowingListenerModule extends Bundle
{
}
