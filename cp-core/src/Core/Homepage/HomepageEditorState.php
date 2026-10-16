<?php

declare(strict_types=1);

namespace App\Core\Homepage;

use App\Core\Localization\LocaleProvider;
use App\Core\Portal\HomepageSimpleContent;
use App\Core\Portal\PortalCopyService;
use App\Core\Portal\PortalLayoutService;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Everything the editor screen needs, as one JSON-ready array.
 *
 * Sections come from the portal catalogue; the fields of a section are the
 * shipped strings of that section (so nothing the page says is out of reach)
 * plus the links and switches the partials read through hp_url()/hp_on().
 * Each field carries its shipped value, so the editor can show what the page
 * says now and offer "back to shipped" per field.
 */
final class HomepageEditorState
{
    /**
     * Links, buttons and elements that live in the template rather than in a
     * translation file. Keys are the ones the partials pass to hp_url()/hp_on().
     *
     * @var array<string, array{text?: list<string>, urls?: list<string>, toggles?: list<string>}>
     */
    private const EXTRAS = [
        'hero' => [
            'text' => ['hero.title'],
            'urls' => ['hero.cta_download@url', 'hero.cta_whitepaper@url', 'hero.github@url'],
            'toggles' => ['hero.subtitle', 'hero.cta_download', 'hero.version', 'hero.cta_whitepaper', 'hero.meta', 'hero.code'],
        ],
        'community' => [
            'text' => ['whitepaper.cta'],
            'urls' => ['community.cta_whitepaper@url', 'community.cta_github@url', 'community.cta_discuss@url'],
            'toggles' => ['community.cta_whitepaper', 'community.cta_github', 'community.cta_discuss'],
        ],
        'roadmap' => [
            'text' => ['nav.roadmap'],
            'urls' => ['roadmap.button@url'],
            'toggles' => ['roadmap.button'],
        ],
    ];

    /** What a link falls back to when the operator has not set one (shown as the placeholder). */
    private const URL_DEFAULTS = [
        'hero.cta_download@url' => 'https://github.com/CPalius/CPalius-Core/releases/latest',
        'hero.github@url' => 'https://github.com/CPalius/CPalius-Core',
        'community.cta_github@url' => 'https://github.com/slaweally',
    ];

    public function __construct(
        private readonly HomepageStore $store,
        private readonly PortalLayoutService $layout,
        private readonly PortalCopyService $copy,
        private readonly HomepageSimpleContent $simple,
        private readonly LocaleProvider $locales,
        private readonly TranslatorInterface $translator,
    ) {
    }

    /** @return array<string, mixed> */
    public function build(): array
    {
        $doc = $this->store->doc();
        $codes = array_values($this->locales->getCodes());
        $catalog = $this->layout->getCatalog();

        $sections = [];
        foreach ($this->layout->getLayout() as $block) {
            $id = (string) ($block['id'] ?? '');
            $meta = $catalog[$id] ?? null;
            if ($meta === null) {
                continue;
            }

            $sections[] = [
                'id' => $id,
                'label' => $this->translator->trans((string) $meta['label']),
                'group' => (string) ($meta['group'] ?? 'marketing'),
                'enabled' => (bool) ($block['enabled'] ?? false),
                'title' => (string) ($block['title'] ?? ''),
                'limit' => $meta['supportsLimit'] ? (int) ($block['limit'] ?? 5) : null,
                'fields' => $this->fields($id, $codes, $doc),
                'toggles' => $this->toggles($id, $doc),
            ];
        }

        return [
            'engine' => $doc['engine'],
            'template' => $this->simple->template(),
            'locales' => array_map(static fn (array $t): array => $t, $this->copy->localeTabs()),
            'sections' => $sections,
            'simple' => [
                'values' => $this->simple->editorValues(),
                'image' => $this->simple->image(),
            ],
            'seo' => $doc['seo'] + ['title' => [], 'description' => []],
            'html' => $doc['html'],
        ];
    }

    /**
     * @param list<string>         $codes
     * @param array<string, mixed> $doc
     *
     * @return list<array<string, mixed>>
     */
    private function fields(string $blockId, array $codes, array $doc): array
    {
        $fields = [];
        $extras = self::EXTRAS[$blockId] ?? [];

        // Template-held links first: they are the ones people could never reach.
        foreach ($extras['urls'] ?? [] as $key) {
            $fields[] = $this->field($key, 'url', $codes, $doc, array_fill_keys($codes, self::URL_DEFAULTS[$key] ?? ''));
        }
        foreach ($extras['text'] ?? [] as $key) {
            $fields[] = $this->field($key, 'text', $codes, $doc, $this->shipped($key, $codes));
        }

        $root = $this->copy->rootOf($blockId);
        $values = $this->copy->valuesForBlock($blockId);
        $seen = [];
        foreach ($codes as $code) {
            foreach (array_keys($values[$code] ?? []) as $relative) {
                if (isset($seen[$relative])) {
                    continue;
                }
                $seen[$relative] = true;
                $key = $root.'.'.$relative;
                $fields[] = $this->field($key, $this->typeOf($relative), $codes, $doc, $this->shipped($key, $codes));
            }
        }

        return $fields;
    }

    /**
     * @param list<string>          $codes
     * @param array<string, mixed>  $doc
     * @param array<string, string> $shipped
     *
     * @return array<string, mixed>
     */
    private function field(string $key, string $type, array $codes, array $doc, array $shipped): array
    {
        $value = [];
        foreach ($codes as $code) {
            $value[$code] = $doc['o'][$key][$code] ?? null;
        }

        // A very long shipped sentence is a paragraph, whatever its key says.
        if ($type === 'text' && max(array_map('mb_strlen', $shipped + [''])) > 90) {
            $type = 'textarea';
        }

        return ['key' => $key, 'label' => $this->label($key), 'type' => $type, 'shipped' => $shipped, 'value' => $value];
    }

    /**
     * @param array<string, mixed> $doc
     *
     * @return list<array{key: string, label: string, hidden: bool}>
     */
    private function toggles(string $blockId, array $doc): array
    {
        $out = [];
        foreach (self::EXTRAS[$blockId]['toggles'] ?? [] as $key) {
            $out[] = ['key' => $key, 'label' => $this->label($key, true), 'hidden' => isset($doc['h'][$key])];
        }

        return $out;
    }

    /**
     * @param list<string> $codes
     *
     * @return array<string, string>
     */
    private function shipped(string $key, array $codes): array
    {
        $out = [];
        foreach ($codes as $code) {
            $text = $this->translator->trans($key, [], 'portal', $code);
            $out[$code] = $text === $key ? '' : $text;
        }

        return $out;
    }

    private function typeOf(string $relative): string
    {
        return match (true) {
            str_contains($relative, '_html') => 'html',
            str_contains($relative, 'code') => 'code',
            \in_array($relative, ['desc', 'description', 'text', 'body'], true),
            (bool) preg_match('/(_desc|_text|_body|_p\d)$/', $relative) => 'textarea',
            default => 'text',
        };
    }

    /**
     * A readable name for a key. Uses the shipped word list (studio.hp.w.*) so
     * the labels follow the panel's language; unknown words are just capitalised.
     */
    private function label(string $key, bool $toggle = false): string
    {
        $special = ($toggle ? 'studio.hp.t.' : 'studio.hp.k.').str_replace(['.', '@'], '_', $key);
        $name = $this->translator->trans($special);
        if ($name !== $special) {
            return $name;
        }

        $parts = preg_split('/[._@]+/', $key) ?: [];
        array_shift($parts); // the section prefix is already the heading above the field
        $words = [];
        foreach ($parts as $word) {
            $id = 'studio.hp.w.'.strtolower($word);
            $t = $this->translator->trans($id);
            $words[] = $t === $id ? ucfirst($word) : $t;
        }

        return implode(' ', $words) ?: $key;
    }
}
