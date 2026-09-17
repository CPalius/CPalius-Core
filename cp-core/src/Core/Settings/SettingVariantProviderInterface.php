<?php

declare(strict_types=1);

namespace App\Core\Settings;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Supplies the options of a select setting whose choices only exist at runtime.
 *
 * #[CpSetting] variants are compiled into the container, which is right for a
 * fixed list like "grid or list" and impossible for one that comes from the
 * database — the categories a site has are not known when the container is
 * built. SettingsRegistry already special-cased two core keys inline; a module
 * cannot add itself to a match arm in core, so the seam is an interface.
 *
 * Implementations are collected by tag and asked in order; the first one that
 * claims a key wins, and a provider that throws or returns nothing leaves the
 * compiled variants alone rather than emptying the dropdown.
 *
 * Tagged on the interface rather than through _instanceof, because a module's
 * services.yaml is loaded by its own YamlFileLoader and never sees core's
 * _instanceof block — the tag has to travel with the interface to reach them.
 */
#[AutoconfigureTag('cpalius.setting_variant_provider')]
interface SettingVariantProviderInterface
{
    public function supports(string $key): bool;

    /**
     * @return array<string, string> value => label, the same shape as CpSetting::$variants
     */
    public function variants(string $key): array;
}
