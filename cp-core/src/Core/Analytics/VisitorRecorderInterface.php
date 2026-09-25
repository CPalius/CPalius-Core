<?php

declare(strict_types=1);

namespace App\Core\Analytics;

/**
 * Core's extension point for counting a page view, deliberately owning nothing
 * about how or where. The VisitorStats module provides the implementation;
 * core only depends on this interface, injected as optional (nullable) —
 * TelemetrySubscriber must keep working, at zero cost, when that module is
 * not installed. A CPalius core repurposed as a CRM/ERP backend with no public
 * site has no use for page-view counting and should not have to carry it.
 */
interface VisitorRecorderInterface
{
    public function record(string $ipAddress): void;
}
