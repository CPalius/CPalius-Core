<?php

declare(strict_types=1);

namespace App\Core\Admin;

use App\Core\Module\ModuleContributionCatalog;

/**
 * Whether a module has taken the Studio panel over, and what it looks like when
 * one has.
 *
 * The case this exists for: an installation that is a hosting panel, or a shop,
 * or a fleet system, and whose operator logs in to work on that and nothing
 * else. Showing them a general-purpose content workspace with their application
 * as one item in the sidebar gets the emphasis backwards. A module can claim the
 * panel, put its own name and logo on it, and reduce the menu to itself plus
 * whatever it says it still needs.
 *
 * What a claim does not get is the ability to restyle the console, replace the
 * layout, or hide AACP. The shell owner renames and reorders; the platform
 * underneath stays exactly where the operator left it, and /aacp is always one
 * link away. A takeover that could break the console it runs inside would be a
 * takeover nobody could safely undo.
 *
 * Resolved once per request: the menu asks on every render and the layout asks
 * three times per page, and none of those should re-walk the contribution
 * catalogue.
 */
final class StudioShell
{
    /** Menu items owned by core rather than by a module. */
    public const CORE_OWNER = 'core';

    /** @var array{brand: string, subtitle: ?string, home_route: ?string, keep: list<string>, regroup: array<string, string>}|null */
    private ?array $claim = null;

    private bool $resolved = false;

    private ?StudioShellBrandingInterface $branding = null;

    private bool $brandingResolved = false;

    /**
     * @param iterable<StudioShellBrandingInterface> $brandingSuppliers
     */
    public function __construct(
        private readonly ModuleContributionCatalog $contributions,
        private readonly iterable $brandingSuppliers,
    ) {
    }

    public function isActive(): bool
    {
        return $this->claim() !== null;
    }

    /**
     * The name that replaces the platform name in the sidebar.
     *
     * A live value from the owning module when it has one — the operator
     * company name they typed into their own settings — and the declared
     * translation key otherwise. Returned raw; the caller translates, because a
     * company name must not be run through the catalogue and a label key must.
     *
     * @return array{text: ?string, key: ?string}
     */
    public function brand(): array
    {
        $claim = $this->claim();

        if ($claim === null) {
            return ['text' => null, 'key' => null];
        }

        $live = $this->brandingSupplier()?->brandName();

        return $live !== null && trim($live) !== ''
            ? ['text' => $live, 'key' => null]
            : ['text' => null, 'key' => $claim['brand']];
    }

    public function subtitleKey(): ?string
    {
        return $this->claim()['subtitle'] ?? null;
    }

    /**
     * Where the sidebar logo links to, so the panel has a home of its own
     * rather than bouncing back to the platform dashboard the shell just hid.
     */
    public function homeRoute(): ?string
    {
        return $this->claim()['home_route'] ?? null;
    }

    public function logoUrl(): ?string
    {
        return $this->isActive() ? $this->brandingSupplier()?->logoUrl() : null;
    }

    /**
     * Whether a Studio menu item survives the takeover.
     *
     * Items belonging to the owning module always do. Anything else has to be
     * named in the claim — which is the module admitting it still needs the
     * media library, or the menu editor, and taking responsibility for saying
     * so rather than leaving an operator to guess why a screen vanished.
     *
     * @param string $ownerModule the bundle class the item came from, or "core"
     */
    public function allows(string $ownerModule, string $routeName): bool
    {
        $claim = $this->claim();

        if ($claim === null) {
            return true;
        }

        if ($ownerModule === $this->ownerModule()) {
            return true;
        }

        return \in_array($routeName, $claim['keep'], true);
    }

    /**
     * The bundle class of the module holding the shell.
     *
     * Read from the branding supplier rather than from the claim, because the
     * contribution catalogue is merged across modules and does not carry which
     * file each entry came from. A module that claims the shell without
     * supplying a branding service keeps its own items by route, not by owner.
     */
    public function ownerModule(): ?string
    {
        $supplier = $this->brandingSupplier();

        if ($supplier === null) {
            return null;
        }

        $namespace = explode('\\', $supplier::class);

        // Modules\Hcms\Studio\X becomes Modules\Hcms\HcmsModule, which is the
        // string MenuItemDefinition carries for every item of that module.
        return \count($namespace) >= 2 && $namespace[0] === 'Modules'
            ? sprintf('Modules\\%s\\%sModule', $namespace[1], $namespace[1])
            : null;
    }

    /**
     * The sidebar heading a kept platform screen should appear under.
     *
     * Keeping a screen and leaving it under its original heading produces a
     * menu that reads as two products stapled together: a Hosting section and,
     * below it, a Content section that came with the platform. A shell owner
     * that has taken responsibility for keeping a screen can also say where it
     * belongs, which is the difference between a panel and a panel plus
     * leftovers.
     *
     * Null means leave the heading alone, which is what every screen the owner
     * has not spoken about gets.
     */
    public function groupFor(string $routeName): ?string
    {
        return $this->claim()['regroup'][$routeName] ?? null;
    }

    /**
     * @return array{brand: string, subtitle: ?string, home_route: ?string, keep: list<string>, regroup: array<string, string>}|null
     */
    private function claim(): ?array
    {
        if (!$this->resolved) {
            $this->claim = $this->contributions->studioShell();
            $this->resolved = true;
        }

        return $this->claim;
    }

    private function brandingSupplier(): ?StudioShellBrandingInterface
    {
        if ($this->brandingResolved) {
            return $this->branding;
        }

        $this->brandingResolved = true;

        foreach ($this->brandingSuppliers as $supplier) {
            try {
                if ($supplier->ownsShell()) {
                    return $this->branding = $supplier;
                }
            } catch (\Throwable) {
                // These read settings, which read the database. A console that
                // will not render because a logo could not be resolved is worse
                // than one rendering with the platform mark.
                continue;
            }
        }

        return $this->branding = null;
    }
}
