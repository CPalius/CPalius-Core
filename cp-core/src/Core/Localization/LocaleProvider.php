<?php

declare(strict_types=1);

namespace App\Core\Localization;

use App\Entity\Locale;
use App\Repository\LocaleRepository;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

/**
 * Single source of active locales. Chain: request memo → cache.app → DB; on failure, .env (never 500).
 * Empty DB uses a 60s cache so install can recover quickly. Do not keep a second ['tr','en'] constant elsewhere.
 */
final class LocaleProvider
{
    public const CACHE_KEY = 'cpalius.locales.active';

    private const CACHE_TTL = 3600;
    private const EMPTY_DB_CACHE_TTL = 60;

    /** @var list<LocaleDefinition>|null */
    private ?array $memo = null;

    /**
     * @param string $fallbackLocales       Comma-separated codes (.env: CPALIUS_LOCALES).
     * @param string $fallbackDefaultLocale .env: CPALIUS_DEFAULT_LOCALE (else kernel.default_locale).
     */
    public function __construct(
        private readonly LocaleRepository $localeRepository,
        private readonly CacheInterface $cache,
        private readonly string $fallbackLocales,
        private readonly string $fallbackDefaultLocale,
    ) {
    }

    /**
     * Active locales, ordered by sortOrder.
     *
     * @return list<LocaleDefinition>
     */
    public function getLocales(): array
    {
        if ($this->memo !== null) {
            return $this->memo;
        }

        try {
            /** @var list<array<string, mixed>> $rows */
            $rows = $this->cache->get(self::CACHE_KEY, function (ItemInterface $item): array {
                $definitions = $this->loadFromDatabase();

                if ($definitions === []) {
                    // Pre-install: short TTL so fallback is not stuck for a full hour.
                    $item->expiresAfter(self::EMPTY_DB_CACHE_TTL);

                    return array_map(
                        static fn (LocaleDefinition $definition): array => $definition->toArray(),
                        $this->buildFallbackDefinitions(),
                    );
                }

                $item->expiresAfter(self::CACHE_TTL);

                return array_map(
                    static fn (LocaleDefinition $definition): array => $definition->toArray(),
                    $definitions,
                );
            });

            $locales = array_values(array_map(LocaleDefinition::fromArray(...), $rows));
        } catch (\Throwable) {
            // DB/cache unreachable: .env fallback. Do not write to cache on this path.
            $locales = $this->buildFallbackDefinitions();
        }

        if ($locales === []) {
            $locales = $this->buildFallbackDefinitions();
        }

        return $this->memo = $locales;
    }

    /**
     * Locale codes for route requirements, Accept-Language, and the switcher.
     *
     * @return list<string>
     */
    public function getCodes(): array
    {
        return array_values(array_map(
            static fn (LocaleDefinition $definition): string => $definition->code,
            $this->getLocales(),
        ));
    }

    public function getDefault(): LocaleDefinition
    {
        $locales = $this->getLocales();

        foreach ($locales as $locale) {
            if ($locale->isDefault) {
                return $locale;
            }
        }

        // No isDefault flag: prefer .env default if it is active, else the first locale.
        foreach ($locales as $locale) {
            if ($locale->code === $this->normalizedFallbackDefault()) {
                return $locale;
            }
        }

        return $locales[0];
    }

    public function getDefaultCode(): string
    {
        return $this->getDefault()->code;
    }

    public function isSupported(?string $code): bool
    {
        return $code !== null && $code !== '' && \in_array($code, $this->getCodes(), true);
    }

    /**
     * Clamp an untrusted code (URL, cookie, Accept-Language) to a supported locale. Never throws.
     */
    public function resolve(?string $code): string
    {
        return $this->isSupported($code) ? (string) $code : $this->getDefaultCode();
    }

    public function find(string $code): ?LocaleDefinition
    {
        foreach ($this->getLocales() as $locale) {
            if ($locale->code === $code) {
                return $locale;
            }
        }

        return null;
    }

    /**
     * Runtime route pattern ("tr|en|de"). Compile-time twin is %cpalius.locales_pattern% (LocalesPatternPass).
     */
    public function getPattern(): string
    {
        return implode('|', array_map(preg_quote(...), $this->getCodes()));
    }

    /**
     * Drop memo and cache after the locale list changes so the next request reloads from DB.
     */
    public function invalidate(): void
    {
        $this->memo = null;

        try {
            if ($this->cache instanceof CacheItemPoolInterface) {
                $this->cache->deleteItem(self::CACHE_KEY);
            }
        } catch (\Throwable) {
            // Cache delete failed: stale list lasts at most TTL — not worth an error page.
        }
    }

    /**
     * @return list<LocaleDefinition>
     */
    private function loadFromDatabase(): array
    {
        $definitions = [];

        foreach ($this->localeRepository->findActive() as $locale) {
            if (!$locale instanceof Locale) {
                continue;
            }

            $definitions[] = new LocaleDefinition(
                code: $locale->getCode(),
                name: $locale->getName(),
                nativeName: $locale->getNativeName(),
                isDefault: $locale->isDefault(),
                sortOrder: $locale->getSortOrder(),
            );
        }

        return $definitions;
    }

    /**
     * Last-resort list from CPALIUS_LOCALES in .env.
     *
     * @return list<LocaleDefinition>
     */
    private function buildFallbackDefinitions(): array
    {
        $codes = $this->parseFallbackCodes();
        $default = $this->normalizedFallbackDefault();

        if (!\in_array($default, $codes, true)) {
            array_unshift($codes, $default);
        }

        $definitions = [];
        $order = 0;

        foreach ($codes as $code) {
            $definitions[] = new LocaleDefinition(
                code: $code,
                name: strtoupper($code),
                nativeName: strtoupper($code),
                isDefault: $code === $default,
                sortOrder: $order++,
            );
        }

        return $definitions;
    }

    /**
     * @return list<string>
     */
    private function parseFallbackCodes(): array
    {
        $codes = [];

        foreach (explode(',', $this->fallbackLocales) as $raw) {
            $code = strtolower(trim($raw));

            if ($code !== '' && preg_match('/^[a-z]{2,5}$/', $code) === 1 && !\in_array($code, $codes, true)) {
                $codes[] = $code;
            }
        }

        return $codes;
    }

    private function normalizedFallbackDefault(): string
    {
        $default = strtolower(trim($this->fallbackDefaultLocale));

        return preg_match('/^[a-z]{2,5}$/', $default) === 1 ? $default : 'tr';
    }
}
