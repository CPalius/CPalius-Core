<?php

declare(strict_types=1);

namespace App\Core\Security\Http;

/**
 * A module that loads a third-party script (an analytics tag, say) names the hosts it needs here,
 * instead of asking the operator to paste them into the CSP settings by hand. Only called while
 * building the header, and a provider should return [] when its feature is off.
 */
interface CspSourceProviderInterface
{
    /**
     * @return array<string, list<string>> directive name (script-src, img-src, connect-src, frame-src) => sources
     */
    public function cspSources(): array;
}
