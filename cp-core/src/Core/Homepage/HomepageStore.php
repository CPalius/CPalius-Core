<?php

declare(strict_types=1);

namespace App\Core\Homepage;

use App\Core\Localization\LocaleProvider;
use App\Core\Portal\HomepageSimpleContent;
use App\Core\Settings\SettingsRegistry;
use App\Entity\Setting;
use App\Repository\SettingRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\HtmlSanitizer\HtmlSanitizer;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerConfig;

/**
 * The homepage document: everything an operator has changed about the front door,
 * as one JSON value.
 *
 *   engine : "blocks" (the theme's sections, every word and link editable) or
 *            "html" (a single page the operator wrote, served sandboxed)
 *   o      : text overrides   key => locale => value. A key that is present wins
 *            over the shipped translation, even when empty — that is how a link or
 *            a sentence is removed rather than merely changed. A key that is
 *            absent keeps the shipped text, so an untouched site follows updates.
 *   h      : hidden elements  key => true
 *   seo    : title / description per locale, share image, noindex
 *   html   : the markup, css and js of the "html" engine
 *
 * Stored as one setting. A draft lives in the cache, per editor, and is what the
 * preview frame renders; publishing copies it here.
 */
final class HomepageStore
{
    public const KEY = 'homepage.doc';
    public const ENGINES = ['blocks', 'html'];

    private const MODULE = 'studio_homepage';
    private const DRAFT_TTL = 7200;
    private const MAX_VALUE = 6000;
    private const MAX_PART = 200000;

    private ?array $doc = null;
    private ?array $draftLayout = null;
    private ?array $draftRaw = null;
    private ?HtmlSanitizer $inline = null;

    public function __construct(
        private readonly SettingsRegistry $settings,
        private readonly SettingRepository $settingRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly CacheItemPoolInterface $cache,
        private readonly LocaleProvider $localeProvider,
    ) {
    }

    // ---- reading ---------------------------------------------------------

    /** @return array{engine: string, o: array<string, array<string, string>>, h: array<string, true>, seo: array<string, mixed>, html: array{html: string, css: string, js: string}} */
    public function doc(): array
    {
        return $this->doc ??= $this->published();
    }

    public function engine(): string
    {
        return $this->doc()['engine'];
    }

    /** The operator's text for this key and language, or null to keep the shipped one. */
    public function override(string $key, string $locale): ?string
    {
        return $this->doc()['o'][$key][$locale] ?? null;
    }

    public function hidden(string $key): bool
    {
        return isset($this->doc()['h'][$key]);
    }

    /** @return array{title: string, description: string, image: string, noindex: bool} */
    public function seo(string $locale): array
    {
        $seo = $this->doc()['seo'];

        return [
            'title' => (string) ($seo['title'][$locale] ?? ''),
            'description' => (string) ($seo['description'][$locale] ?? ''),
            'image' => (string) ($seo['image'] ?? ''),
            'noindex' => (bool) ($seo['noindex'] ?? false),
        ];
    }

    /** @return array{html: string, css: string, js: string} */
    public function html(): array
    {
        return $this->doc()['html'];
    }

    // ---- preview ---------------------------------------------------------

    /** Make this request render the editor's draft instead of the published page. */
    public function usePreview(string $editorId): bool
    {
        $item = $this->cache->getItem($this->draftKey($editorId));
        if (!$item->isHit() || !\is_array($item->get())) {
            return false;
        }

        $draft = $item->get();
        $this->draftRaw = $draft;
        $this->draftLayout = \is_array($draft['layout'] ?? null) ? $draft['layout'] : null;
        $this->doc = self::normalize($draft, $this->localeCodes(), $this->inlineSanitizer());

        return true;
    }

    /** The editor's whole draft (template, simple copy, layout) while previewing, else null. */
    public function draftRaw(): ?array
    {
        return $this->draftRaw;
    }

    /** @return list<array<string, mixed>>|null */
    public function draftLayout(): ?array
    {
        return $this->draftLayout;
    }

    /** @param array<string, mixed> $input */
    public function saveDraft(string $editorId, array $input): void
    {
        $item = $this->cache->getItem($this->draftKey($editorId));
        $item->set($input)->expiresAfter(self::DRAFT_TTL);
        $this->cache->save($item);
    }

    // ---- writing ---------------------------------------------------------

    /** @param array<string, mixed> $input */
    public function save(array $input): void
    {
        $doc = self::normalize($input, $this->localeCodes(), $this->inlineSanitizer());
        $json = json_encode($doc, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR);

        $setting = $this->settingRepository->findOneBy(['settingKey' => self::KEY]);
        if (!$setting instanceof Setting) {
            $setting = new Setting(self::KEY, self::MODULE);
            $this->entityManager->persist($setting);
        }
        $setting->setSettingValue($json);
        $this->entityManager->flush();
        $this->settings->clearCache();
        $this->doc = $doc;
    }

    // ---- normalising -----------------------------------------------------

    /**
     * Turns whatever the editor posted into a document that is safe to store and
     * to render. Pure: no I/O, so it can be exercised without a kernel.
     *
     * @param array<string, mixed> $in
     * @param list<string>         $locales active language codes
     *
     * @return array{engine: string, o: array<string, array<string, string>>, h: array<string, true>, seo: array<string, mixed>, html: array{html: string, css: string, js: string}}
     */
    public static function normalize(array $in, array $locales, ?HtmlSanitizer $inline = null): array
    {
        $inline ??= self::buildInlineSanitizer();
        $engine = \in_array($in['engine'] ?? null, self::ENGINES, true) ? (string) $in['engine'] : 'blocks';

        $o = [];
        foreach (\is_array($in['o'] ?? null) ? $in['o'] : [] as $key => $byLocale) {
            if (!\is_string($key) || preg_match('/^[a-z0-9_.@-]{1,90}$/i', $key) !== 1 || !\is_array($byLocale)) {
                continue;
            }
            foreach ($byLocale as $locale => $value) {
                if (!\is_string($locale) || !\in_array($locale, $locales, true) || !\is_scalar($value)) {
                    continue;
                }
                $o[$key][$locale] = self::cleanValue($key, (string) $value, $inline);
            }
        }

        $h = [];
        foreach (\is_array($in['h'] ?? null) ? $in['h'] : [] as $key => $on) {
            if (\is_string($key) && preg_match('/^[a-z0-9_.@-]{1,90}$/i', $key) === 1 && $on) {
                $h[$key] = true;
            }
        }

        $seoIn = \is_array($in['seo'] ?? null) ? $in['seo'] : [];
        $seo = [
            'title' => self::perLocale($seoIn['title'] ?? null, $locales, 180),
            'description' => self::perLocale($seoIn['description'] ?? null, $locales, 400),
            'image' => HomepageSimpleContent::safeUrl(trim((string) ($seoIn['image'] ?? ''))),
            'noindex' => !empty($seoIn['noindex']),
        ];

        $htmlIn = \is_array($in['html'] ?? null) ? $in['html'] : [];
        $html = [];
        foreach (['html', 'css', 'js'] as $part) {
            $html[$part] = mb_substr(str_replace("\0", '', (string) ($htmlIn[$part] ?? '')), 0, self::MAX_PART);
        }

        return ['engine' => $engine, 'o' => $o, 'h' => $h, 'seo' => $seo, 'html' => $html];
    }

    /** Link fields hold a URL and nothing else; "_html" fields hold a small, safe set of tags; the rest is text. */
    private static function cleanValue(string $key, string $value, HtmlSanitizer $inline): string
    {
        $value = mb_substr(preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', trim($value)) ?? '', 0, self::MAX_VALUE);

        if (str_ends_with($key, '@url')) {
            return $value === '' ? '' : self::safeUrl($value);
        }
        if (str_contains($key, '_html')) {
            return $inline->sanitize($value);
        }

        return $value;
    }

    /** HomepageSimpleContent::safeUrl plus in-page anchors and telephone links. */
    public static function safeUrl(string $url): string
    {
        if (preg_match('~^(#[\w-]*|tel:[+\d\s().-]+)$~', $url) === 1) {
            return $url;
        }

        return HomepageSimpleContent::safeUrl($url);
    }

    /** @param list<string> $locales @return array<string, string> */
    private static function perLocale(mixed $value, array $locales, int $max): array
    {
        $out = [];
        foreach (\is_array($value) ? $value : [] as $locale => $text) {
            if (\is_string($locale) && \in_array($locale, $locales, true) && \is_scalar($text)) {
                $out[$locale] = mb_substr(trim((string) $text), 0, $max);
            }
        }

        return $out;
    }

    // ---- internals -------------------------------------------------------

    /** @return array<string, mixed> */
    private function published(): array
    {
        $raw = (string) $this->settings->get(self::KEY, '');
        $decoded = $raw !== '' ? json_decode($raw, true) : null;

        return self::normalize(\is_array($decoded) ? $decoded : [], $this->localeCodes(), $this->inlineSanitizer());
    }

    /** @return list<string> */
    private function localeCodes(): array
    {
        return array_values($this->localeProvider->getCodes());
    }

    private function draftKey(string $editorId): string
    {
        return 'homepage_draft_'.preg_replace('/[^A-Za-z0-9_]/', '_', $editorId);
    }

    private function inlineSanitizer(): HtmlSanitizer
    {
        return $this->inline ??= self::buildInlineSanitizer();
    }

    private static function buildInlineSanitizer(): HtmlSanitizer
    {
        $config = (new HtmlSanitizerConfig())
            ->allowLinkSchemes(['http', 'https', 'mailto'])
            ->allowRelativeLinks()
            ->forceAttribute('a', 'rel', 'noopener noreferrer');

        foreach (['strong', 'b', 'em', 'i', 'u', 'code', 'kbd', 'br', 'small', 'mark', 'sup', 'sub'] as $tag) {
            $config = $config->allowElement($tag);
        }
        $config = $config->allowElement('span', ['class'])->allowElement('a', ['href', 'target', 'title']);

        return new HtmlSanitizer($config);
    }
}
