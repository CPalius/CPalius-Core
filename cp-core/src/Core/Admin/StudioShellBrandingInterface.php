<?php

declare(strict_types=1);

namespace App\Core\Admin;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Supplies the live name and logo of a module that has taken over Studio.
 *
 * The contributions file can only hold a translation key, which is right for
 * something fixed and wrong for the thing an operator actually wants at the top
 * of their panel: their own company name and their own logo, typed into a
 * settings screen after the module shipped.
 *
 * So the static claim declares the takeover and this supplies what it looks
 * like. Both parts are optional in the sense that returning null from either
 * method falls back to the declaration — a module that has not been configured
 * yet shows its own name rather than an empty header.
 *
 * Tagged on the interface rather than through _instanceof, because a module
 * services.yaml is loaded by its own YamlFileLoader and never sees the core
 * _instanceof block; the tag has to travel with the interface to reach them.
 */
#[AutoconfigureTag('cpalius.studio_shell_branding')]
interface StudioShellBrandingInterface
{
    /**
     * Whether this supplier is the one in charge. Asked because the container
     * collects every implementation, and only the module that actually holds
     * the shell should be answering.
     */
    public function ownsShell(): bool;

    /**
     * The name shown where the platform name was. Null keeps the declared one.
     */
    public function brandName(): ?string;

    /**
     * A URL for the sidebar logo, or null for the platform mark.
     */
    public function logoUrl(): ?string;
}
