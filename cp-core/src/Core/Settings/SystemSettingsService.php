<?php

declare(strict_types=1);

namespace App\Core\Settings;

use App\Core\Localization\LocaleDefinition;
use App\Core\Localization\LocaleProvider;
use App\Entity\Setting;
use App\Repository\SettingRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Tabs and persistence logic for the AACP System Management screen.
 */
final class SystemSettingsService
{
    public const CSRF_TOKEN_ID = 'aacp_system_settings';

    public function __construct(
        private readonly LocaleProvider $localeProvider,
    ) {
    }

    /**
     * @return array<string, array{label: string, icon: string, description: string, isLocales?: bool}>
     */
    public function tabs(): array
    {
        return [
            'general' => [
                'label' => 'aacp.system_settings.tab.general',
                'icon' => 'heroicons:cog-6-tooth',
                'description' => 'aacp.system_settings.tab.general_desc',
            ],
            'email' => [
                'label' => 'aacp.system_settings.tab.email',
                'icon' => 'heroicons:envelope',
                'description' => 'aacp.system_settings.tab.email_desc',
            ],
            'security' => [
                'label' => 'aacp.system_settings.tab.security',
                'icon' => 'heroicons:shield-check',
                'description' => 'aacp.system_settings.tab.security_desc',
            ],
            'registration' => [
                'label' => 'aacp.system_settings.tab.registration',
                'icon' => 'heroicons:user-plus',
                'description' => 'aacp.system_settings.tab.registration_desc',
            ],
            'locales' => [
                'label' => 'aacp.system_settings.tab.locales',
                'icon' => 'heroicons:language',
                'description' => 'aacp.system_settings.tab.locales_desc',
                'isLocales' => true,
            ],
        ];
    }

    /**
     * @return list<SettingDefinition>
     */
    public function definitionsForTab(string $tab, SettingsRegistry $settingsRegistry): array
    {
        $filters = $this->filtersForTab($tab);

        if ($filters === null) {
            return [];
        }

        return array_values(array_filter(
            $settingsRegistry->all(),
            static function (SettingDefinition $definition) use ($filters): bool {
                foreach ($filters as $filter) {
                    if ($definition->module === $filter['module'] && $definition->group === $filter['group']) {
                        return true;
                    }
                }

                return false;
            },
        ));
    }

    /**
     * Translatable settings return the FULL locale map, never the resolved string:
     * saving while the panel is in English must not overwrite the Turkish text.
     *
     * @param list<SettingDefinition> $definitions
     *
     * @return array<string, mixed>
     */
    public function currentValuesForDefinitions(array $definitions, SettingsRegistry $settingsRegistry): array
    {
        $values = [];

        foreach ($definitions as $definition) {
            $values[$definition->key] = $definition->isTranslatable()
                ? $settingsRegistry->getTranslations($definition->key)
                : $settingsRegistry->get($definition->key);
        }

        return $values;
    }

    /**
     * Active locales, so the form can render one input per language.
     *
     * @return list<LocaleDefinition>
     */
    public function locales(): array
    {
        return $this->localeProvider->getLocales();
    }

    /**
     * @param array<string, string|array<string, string|null>|null> $submitted
     *   Translatable settings arrive nested: settings[core.site_name][tr].
     */
    public function updateTab(
        string $tab,
        array $submitted,
        SettingsRegistry $settingsRegistry,
        SettingRepository $settingRepository,
        EntityManagerInterface $entityManager,
    ): ?string {
        $definitions = $this->definitionsForTab($tab, $settingsRegistry);

        if ($definitions === []) {
            return null;
        }

        $keys = array_map(static fn (SettingDefinition $d) => $d->key, $definitions);
        $existing = $settingRepository->findIndexedByKeys($keys);
        $invalidKey = null;
        $pendingValues = [];

        foreach ($definitions as $definition) {
            $raw = $submitted[$definition->key] ?? null;

            // Translatable settings arrive as one input per locale and are
            // persisted as a single JSON map.
            if ($definition->isTranslatable()) {
                $encoded = $this->encodeTranslationMap($raw);

                if ($encoded !== null) {
                    $pendingValues[$definition->key] = [$definition, $encoded];
                }

                continue;
            }

            $value = match ($definition->type) {
                // An unchecked checkbox is never submitted, so a missing key means false.
                'checkbox' => $raw !== null ? '1' : '0',
                'password' => \is_string($raw) && trim($raw) !== '' ? trim($raw) : null,
                default => \is_string($raw) ? trim($raw) : null,
            };

            if ($value === null) {
                continue;
            }

            if ($definition->type === 'integer' && !$this->isValidInteger($value)) {
                $invalidKey = $definition->key;
                continue;
            }

            if ($definition->type === 'select' && !$this->isValidSelect($definition, $value)) {
                $invalidKey = $definition->key;
                continue;
            }

            $pendingValues[$definition->key] = [$definition, $value];
        }

        if ($invalidKey !== null) {
            return $invalidKey;
        }

        foreach ($pendingValues as $key => [$definition, $value]) {
            $setting = $existing[$key] ?? null;

            if (!$setting instanceof Setting) {
                $setting = new Setting($definition->key, $definition->module);
                $entityManager->persist($setting);
            }

            $setting->setSettingValue($value);
        }

        $entityManager->flush();
        $settingsRegistry->clearCache();

        return null;
    }

    /**
     * Encodes the submitted locale => text array into the JSON map stored in cp_settings.
     * Public because AACPController shares this encoder; two writers must agree on the format.
     *
     * @param string|array<string, string|null>|null $raw
     */
    public function encodeTranslationMap(string|array|null $raw): ?string
    {
        $activeCodes = $this->localeProvider->getCodes();

        // A plain string means the form predates the translatable flag: keep the
        // author's text by spreading it across every active locale.
        if (\is_string($raw)) {
            $raw = array_fill_keys($activeCodes, $raw);
        }

        if (!\is_array($raw)) {
            return null;
        }

        $map = [];

        // Only active locales are written: a locale disabled after the form was
        // opened must not silently resurrect stale text later on.
        foreach ($activeCodes as $code) {
            $value = $raw[$code] ?? null;

            if (\is_string($value)) {
                $map[$code] = trim($value);
            }
        }

        if ($map === []) {
            return null;
        }

        $json = json_encode($map, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES);

        // Fail-safe: on invalid UTF-8, skip this setting instead of corrupting it.
        return $json !== false ? $json : null;
    }

    /**
     * @return list<array{module: string, group: string}>|null
     */
    private function filtersForTab(string $tab): ?array
    {
        return match ($tab) {
            'general' => [['module' => 'core', 'group' => 'genel']],
            'email' => [['module' => 'core', 'group' => 'mail']],
            'security' => [['module' => 'core', 'group' => 'security']],
            'registration' => [['module' => 'account', 'group' => 'account.registration']],
            'locales' => null,
            default => null,
        };
    }

    private function isValidInteger(string $value): bool
    {
        return preg_match('/^-?\d+$/', $value) === 1;
    }

    private function isValidSelect(SettingDefinition $definition, string $value): bool
    {
        if ($definition->variants === []) {
            return true;
        }

        return array_key_exists($value, $definition->variants);
    }
}
