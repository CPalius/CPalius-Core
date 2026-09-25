<?php

declare(strict_types=1);

namespace Modules\VisitorStats;

use Symfony\Component\HttpKernel\Bundle\Bundle;

/**
 * Page-view/unique-visitor counting, split out of core so a CPalius install
 * used as a headless CRM/ERP backend — no public site to count visits on —
 * is not carrying it. Core defines VisitorRecorderInterface and
 * VisitorStatsProviderInterface (App\Core\Analytics\); this module is the
 * only thing that implements them. Deactivate it and page views simply stop
 * being counted — core keeps working, at zero cost, because it never
 * depended on this module's classes existing at all, only on the interfaces.
 */
class VisitorStatsModule extends Bundle
{
}
