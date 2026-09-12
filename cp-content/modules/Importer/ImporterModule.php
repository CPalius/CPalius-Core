<?php

declare(strict_types=1);

namespace Modules\Importer;

use Symfony\Component\HttpKernel\Bundle\Bundle;

/**
 * Source drivers for importing from other systems.
 *
 * WHY THIS IS A MODULE AND NOT CORE
 * The core Migrate API knows how to write CPalius entities and nothing about
 * anyone else's. Teaching it what a WordPress export or a XenForo database
 * looks like would put a permanent tax on every installation that never
 * migrates from anywhere — which is most of them — and would grow with every
 * system anyone ever wants to leave. The split is: core writes CPalius, this
 * module reads foreign systems. Deactivate it when the migration is done and
 * the knowledge leaves with it.
 */
class ImporterModule extends Bundle
{
}
