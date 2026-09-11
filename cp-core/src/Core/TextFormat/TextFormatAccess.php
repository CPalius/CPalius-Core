<?php

declare(strict_types=1);

namespace App\Core\TextFormat;

use Symfony\Bundle\SecurityBundle\Security;

/**
 * Which named formats the current user may select. Unknown = deny (CBAC).
 * A field may further restrict the list via its `allowed_formats` setting.
 */
final class TextFormatAccess
{
    public function __construct(
        private readonly TextFormatRegistry $formats,
        private readonly Security $security,
    ) {
    }

    /**
     * @param list<string>|null $fieldAllowlist empty/null = every registered format
     *
     * @return array<string, string> id => label translation key, in registry order
     */
    public function usableChoices(?array $fieldAllowlist = null): array
    {
        $out = [];
        foreach ($this->formats->all() as $id => $format) {
            if ($fieldAllowlist !== null && $fieldAllowlist !== [] && !\in_array($id, $fieldAllowlist, true)) {
                continue;
            }
            if (!$this->security->isGranted($format->capability())) {
                continue;
            }
            $out[$id] = $format->label;
        }

        return $out;
    }

    /**
     * Pick a format the user is allowed to use, preferring $requested then
     * $fallback then the first usable, then restricted. Never returns a format
     * the user cannot use — a crafted POST of `full_html` from a member
     * silently falls back (Drupal lets you keep a format you no longer have
     * access to, which is how old Full HTML posts stay exploitable).
     */
    public function resolve(string $requested, string $fallback = TextFormatRegistry::BASIC_HTML, ?array $fieldAllowlist = null): string
    {
        $usable = array_keys($this->usableChoices($fieldAllowlist));
        if ($usable === []) {
            if ($this->security->getUser() === null && $this->formats->has($fallback)) {
                return $fallback;
            }

            return TextFormatRegistry::RESTRICTED;
        }
        if (\in_array($requested, $usable, true)) {
            return $requested;
        }
        if (\in_array($fallback, $usable, true)) {
            return $fallback;
        }

        return $usable[0];
    }
}
