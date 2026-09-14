<?php

declare(strict_types=1);

namespace Modules\Whitepaper;

use Symfony\Component\HttpKernel\Bundle\Bundle;

/**
 * The public technical whitepaper: a document CPalius's own site publishes.
 *
 * It is a module and not core on purpose. An installation that builds an ERP,
 * a CRM or a hosting panel has no whitepaper, and must not carry two tables,
 * two entities and a route for one. Deactivating this module removes the
 * screens and the public page; uninstalling drops the tables.
 */
class WhitepaperModule extends Bundle
{
}
