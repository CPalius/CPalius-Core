<?php

declare(strict_types=1);

namespace App\Core\Settings;

use App\Core\Localization\LocaleProvider;
use App\Repository\SettingRepository;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Throwable;

/**
 * Source of truth for #[CpSetting] definitions (compile-time) and values (lazy DB, one query).
 * Translatable keys are JSON locale maps with request → default → first non-empty → $default fallback.
 * Values are memoised per request and stored in cache.app (PERF-03); writes must call clearCache().
 */
final class SettingsRegistry
{
    public const CACHE_KEY = 'cpalius.settings.values';

    private const CACHE_TTL = 3600;

    public const DEFAULT_LOCALE_KEY = 'core.default_locale';

    /** @var array<string, SettingDefinition> */
    private array $definitions = [];

    /** @var array<string, string>|null Lazy-loaded DB overrides. */
    private ?array $values = null;

    public function __construct(
        private readonly SettingRepository $repository,
        private readonly LocaleProvider $localeProvider,
        private readonly RequestStack $requestStack,
        private readonly CacheInterface $cache,
    ) {
    }

    public function addDefinition(SettingDefinition $definition): void
    {
        $this->definitions[$definition->key] = $definition;
    }

    /**
     * @return list<SettingDefinition>
     */
    public function all(): array
    {
        return array_values(array_map(
            $this->hydrateDynamicVariants(...),
            $this->definitions,
        ));
    }

    public function getDefinition(string $key): ?SettingDefinition
    {
        $definition = $this->definitions[$key] ?? null;

        return $definition === null ? null : $this->hydrateDynamicVariants($definition);
    }

    /**
     * Value in the active locale. Missing keys return $default (no exception); DB rows are type-cast.
     *
     * @param mixed $default Returned when the key has no definition.
     */
    public function get(string $key, mixed $default = null): mixed
    {
        return $this->resolve($key, null, $default);
    }

    /**
     * Value for an explicit locale (email, sitemap, AACP preview) — independent of the request.
     */
    public function getForLocale(string $key, string $locale, mixed $default = null): mixed
    {
        return $this->resolve($key, $locale, $default);
    }

    /**
     * Raw per-locale values for AACP (every active locale, empty string if missing). Non-translatable → [].
     *
     * @return array<string, string>
     */
    public function getTranslations(string $key): array
    {
        $definition = $this->definitions[$key] ?? null;

        if ($definition === null || !$definition->isTranslatable()) {
            return [];
        }

        $map = $this->decodeMap($this->loadValues()[$key] ?? null);
        $fallback = \is_string($definition->default) ? str_replace('%%', '%', trim($definition->default)) : '';
        $result = [];

        foreach ($this->localeProvider->getCodes() as $code) {
            $value = $map[$code] ?? '';
            $result[$code] = trim($value) !== '' ? $value : $fallback;
        }

        return $result;
    }

    /**
     * Drop the in-request memo and the shared cache.app entry after cp_settings is updated.
     */
    public function clearCache(): void
    {
        $this->values = null;

        try {
            if ($this->cache instanceof CacheItemPoolInterface) {
                $this->cache->deleteItem(self::CACHE_KEY);
            }
        } catch (Throwable) {
            // Stale cache lasts at most TTL; a settings write must not 500.
        }
    }

    /**
     * Shared body for get() / getForLocale().
     */
    private function resolve(string $key, ?string $locale, mixed $default): mixed
    {
        $definition = $this->definitions[$key] ?? null;

        if ($definition === null) {
            return $default;
        }

        $raw = $this->loadValues()[$key] ?? null;

        if ($raw === null) {
            return $definition->default;
        }

        if (!$definition->isTranslatable()) {
            return $this->castValue($raw, $definition->type);
        }

        $resolved = $this->resolveFromMap($this->decodeMap($raw), $locale ?? $this->currentLocale());

        // Empty map → definition default so the UI never shows a blank site name.
        return $resolved ?? $definition->default;
    }

    /**
     * Four-step fallback: request locale, site default, first non-empty map value, then null.
     *
     * @param array<string, string> $map
     */
    private function resolveFromMap(array $map, string $locale): ?string
    {
        $candidates = [$locale, $this->localeProvider->getDefaultCode()];

        foreach ($candidates as $candidate) {
            $value = $map[$candidate] ?? null;

            if (\is_string($value) && trim($value) !== '') {
                return $value;
            }
        }

        // Last resort: any non-empty map value so a partial translation still shows text.
        foreach ($map as $value) {
            if (trim($value) !== '') {
                return $value;
            }
        }

        return null;
    }

    /**
     * Decode a JSON locale map. Non-JSON (legacy plain text) is treated as the value for every locale.
     *
     * @return array<string, string>
     */
    private function decodeMap(?string $raw): array
    {
        if ($raw === null || trim($raw) === '') {
            return [];
        }

        // Skip json_decode when the value is clearly not a JSON object.
        if (!str_starts_with(ltrim($raw), '{')) {
            return $this->spreadToAllLocales($raw);
        }

        try {
            $decoded = json_decode($raw, true, 8, \JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return $this->spreadToAllLocales($raw);
        }

        if (!\is_array($decoded)) {
            return $this->spreadToAllLocales($raw);
        }

        $map = [];

        foreach ($decoded as $code => $value) {
            if (\is_string($code) && \is_string($value)) {
                $map[$code] = $value;
            }
        }

        return $map;
    }

    /**
     * @return array<string, string>
     */
    private function spreadToAllLocales(string $value): array
    {
        $map = [];

        foreach ($this->localeProvider->getCodes() as $code) {
            $map[$code] = $value;
        }

        return $map;
    }

    /**
     * Request locale, or the site default when there is no request (CLI, warmup, worker).
     */
    private function currentLocale(): string
    {
        $request = $this->requestStack->getCurrentRequest();

        return $request !== null
            ? $this->localeProvider->resolve($request->getLocale())
            : $this->localeProvider->getDefaultCode();
    }

    /**
     * Select options that cannot be compiled in: core.default_locale follows LocaleProvider.
     */
    private function hydrateDynamicVariants(SettingDefinition $definition): SettingDefinition
    {
        if ($definition->key !== self::DEFAULT_LOCALE_KEY) {
            return $definition;
        }

        $variants = [];

        foreach ($this->localeProvider->getLocales() as $locale) {
            if ($locale->code === '') {
                continue;
            }

            $variants[$locale->code] = $locale->nativeName !== '' ? $locale->nativeName : $locale->name;
        }

        return $variants === [] ? $definition : $definition->withVariants($variants);
    }

    /**
     * @return array<string, string>
     */
    private function loadValues(): array
    {
        if ($this->values !== null) {
            return $this->values;
        }

        try {
            /** @var array<string, string> $map */
            $map = $this->cache->get(self::CACHE_KEY, function (ItemInterface $item): array {
                $item->expiresAfter(self::CACHE_TTL);

                return $this->repository->findAllAsMap();
            });

            return $this->values = $map;
        } catch (Throwable) {
            return $this->values = $this->repository->findAllAsMap();
        }
    }

    private function castValue(string $raw, string $type): mixed
    {
        return match ($type) {
            'checkbox', 'boolean' => $raw === '1',
            'integer' => (int) $raw,
            default => $raw,
        };
    }
}
