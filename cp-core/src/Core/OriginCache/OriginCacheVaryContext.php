<?php

declare(strict_types=1);

namespace App\Core\OriginCache;

use App\Core\Localization\LocaleProvider;
use Symfony\Component\HttpFoundation\Request;

/**
 * GC1: disk cache vary dimensions. v1 stores only anonymous snapshots; authenticated
 * requests bypass both write and read. Locale is always part of the key so /tr and /en
 * never share a file even when pathInfo coincidentally matches.
 */
final readonly class OriginCacheVaryContext
{
    public const VISIBILITY_ANON = 'anon';
    public const VISIBILITY_AUTH = 'auth';

    public function __construct(
        public string $locale,
        public string $visibility,
    ) {
        if ($this->visibility !== self::VISIBILITY_ANON && $this->visibility !== self::VISIBILITY_AUTH) {
            throw new \InvalidArgumentException('Invalid origin-cache visibility.');
        }
    }

    public static function anonymous(string $locale): self
    {
        $locale = preg_replace('/[^a-z0-9_-]/i', '', $locale) ?: 'und';

        return new self(strtolower($locale), self::VISIBILITY_ANON);
    }

    public static function fromRequest(Request $request, LocaleProvider $locales, bool $authenticated): self
    {
        $locale = (string) ($request->attributes->get('_locale') ?? $locales->getDefaultCode());
        if ($locale === '') {
            $locale = $locales->getDefaultCode();
        }

        return new self(
            strtolower(preg_replace('/[^a-z0-9_-]/i', '', $locale) ?: 'und'),
            $authenticated ? self::VISIBILITY_AUTH : self::VISIBILITY_ANON,
        );
    }

    public function pathPrefix(): string
    {
        return '_ctx/'.$this->locale.'/'.$this->visibility;
    }
}
