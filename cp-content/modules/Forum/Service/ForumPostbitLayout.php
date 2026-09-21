<?php

declare(strict_types=1);

namespace Modules\Forum\Service;

use App\Core\Settings\SettingsRegistry;

/**
 * Which blocks the postbit shows, and in what order.
 *
 * The postbit used to be a fixed run of markup in the theme, so "hide the join
 * date" or "put the rank above the name" meant editing a template that the next
 * theme update would overwrite. The arrangement is a site's decision, so it
 * lives in settings and the template renders whatever this returns.
 *
 * The catalogue of blocks stays in code on purpose: each one has markup behind
 * it, and an entry an operator could invent would name a partial that does not
 * exist. Order and visibility are theirs; the vocabulary is not.
 */
final class ForumPostbitLayout
{
    public const SETTING_ORDER = 'forum.postbit_order';
    public const SETTING_HIDDEN = 'forum.postbit_hidden';
    public const SETTING_CSS = 'forum.postbit_css';

    /**
     * Every block the postbit can render, in the order it ships.
     *
     * The key is both the settings token and the partial name, so adding a
     * block is one entry plus one file and nothing else.
     *
     * @var list<string>
     */
    public const ELEMENTS = [
        'avatar',
        'name',
        'custom_title',
        'op_badge',
        'rank',
        'stats',
        'joined',
        'actions',
    ];

    /**
     * Blocks that carry identity. Hiding every one of them leaves a column of
     * numbers attached to nobody, so the resolver refuses to.
     *
     * @var list<string>
     */
    public const IDENTITY_ELEMENTS = ['avatar', 'name'];

    public function __construct(
        private readonly SettingsRegistry $settingsRegistry,
    ) {
    }

    /**
     * The blocks to render, already ordered and filtered.
     *
     * @return list<string>
     */
    public function visibleElements(): array
    {
        $hidden = $this->hiddenElements();

        $visible = array_values(array_filter(
            $this->orderedElements(),
            static fn (string $element): bool => !\in_array($element, $hidden, true),
        ));

        // A postbit with no avatar and no name is not a postbit. Rather than
        // validate this on the way in and leave a saved-but-broken state
        // possible, the read path guarantees it.
        foreach (self::IDENTITY_ELEMENTS as $element) {
            if (!\in_array($element, $visible, true)) {
                array_unshift($visible, $element);
            }
        }

        return array_values(array_unique($visible));
    }

    /**
     * Guest postbit: username and photo only, even if Studio shows more.
     *
     * @return list<string>
     */
    public function guestVisibleElements(): array
    {
        return array_values(array_filter(
            $this->visibleElements(),
            static fn (string $element): bool => \in_array($element, self::IDENTITY_ELEMENTS, true),
        ));
    }

    /**
     * Full order including hidden blocks — the admin screen lists everything.
     *
     * @return list<string>
     */
    public function orderedElements(): array
    {
        $stored = $this->decodeList($this->settingsRegistry->get(self::SETTING_ORDER));

        $ordered = array_values(array_filter(
            $stored,
            static fn (string $element): bool => \in_array($element, self::ELEMENTS, true),
        ));

        // A block added by a later release is not in the stored order yet.
        // Appending it beats dropping it: a new feature should show up, and an
        // operator who does not want it can hide it.
        foreach (self::ELEMENTS as $element) {
            if (!\in_array($element, $ordered, true)) {
                $ordered[] = $element;
            }
        }

        return array_values(array_unique($ordered));
    }

    /**
     * @return list<string>
     */
    public function hiddenElements(): array
    {
        return array_values(array_filter(
            $this->decodeList($this->settingsRegistry->get(self::SETTING_HIDDEN)),
            static fn (string $element): bool => \in_array($element, self::ELEMENTS, true),
        ));
    }

    /**
     * Operator CSS for the postbit, safe to place inside a <style> block.
     *
     * The only real hazard in a stylesheet that the browser parses as CSS is
     * escaping the element itself, so anything that could close the tag or open
     * a comment that swallows it is removed. What is left is still CSS the
     * operator wrote, including selectors this project never anticipated —
     * which is the point of the field.
     */
    public function css(): string
    {
        $css = trim((string) $this->settingsRegistry->get(self::SETTING_CSS, ''));

        if ($css === '') {
            return '';
        }

        // "</style>", "</ style>", "<!--" and "-->" all end or reinterpret the
        // block; none of them mean anything inside real CSS.
        $css = (string) preg_replace('#</\s*style#i', '', $css);
        $css = str_replace(['<!--', '-->', '<script', '</script'], '', $css);

        return trim($css);
    }

    /**
     * @param list<string> $order
     * @param list<string> $hidden
     *
     * @return array{order: string, hidden: string} values ready for cp_settings
     */
    public function encode(array $order, array $hidden): array
    {
        $order = array_values(array_filter(
            $order,
            static fn (mixed $element): bool => \is_string($element) && \in_array($element, self::ELEMENTS, true),
        ));

        foreach (self::ELEMENTS as $element) {
            if (!\in_array($element, $order, true)) {
                $order[] = $element;
            }
        }

        $hidden = array_values(array_filter(
            $hidden,
            static fn (mixed $element): bool => \is_string($element) && \in_array($element, self::ELEMENTS, true),
        ));

        return [
            'order' => implode(',', array_unique($order)),
            'hidden' => implode(',', array_unique($hidden)),
        ];
    }

    /**
     * Comma-separated rather than JSON: the value goes into a plain settings
     * row, and a malformed JSON blob there would be a silent empty layout.
     *
     * @return list<string>
     */
    private function decodeList(mixed $raw): array
    {
        if (!\is_string($raw) || trim($raw) === '') {
            return [];
        }

        return array_values(array_filter(array_map('trim', explode(',', $raw))));
    }
}
