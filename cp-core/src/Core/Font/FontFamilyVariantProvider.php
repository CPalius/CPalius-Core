<?php

declare(strict_types=1);

namespace App\Core\Font;

use App\Core\Settings\SettingVariantProviderInterface;

/**
 * Fills the font dropdowns with what is actually installed, plus the stacks
 * every visitor already has.
 *
 * The installed list lives on disk and changes whenever an operator adds or
 * removes a family, so it cannot be a compiled #[CpSetting] variant.
 */
final class FontFamilyVariantProvider implements SettingVariantProviderInterface
{
    public function __construct(
        private readonly FontLibrary $library,
    ) {
    }

    public function supports(string $key): bool
    {
        return \in_array($key, array_map(FontRole::settingKey(...), FontRole::all()), true);
    }

    public function variants(string $key): array
    {
        if (!$this->supports($key)) {
            return [];
        }

        // The empty option has to exist and has to come first: "no override" is
        // the shipped state, not an invisible default that silently picks the
        // alphabetically first font somebody happened to install.
        $variants = ['' => 'aacp.fonts.role.inherit'];

        foreach ($this->library->families() as $family) {
            // Braces are stripped for the same reason the blog category provider
            // strips them: labels go through an ICU catalogue, where an
            // unbalanced brace is a syntax error that takes the screen down.
            $variants[$family->slug] = str_replace(['{', '}'], '', $family->name);
        }

        foreach (array_keys(FontStack::all()) as $value) {
            $variants[$value] = FontStack::labelKey($value);
        }

        return $variants;
    }
}
