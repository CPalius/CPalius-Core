<?php

declare(strict_types=1);

namespace Modules\Forum\Service;

/**
 * Render-time rewrite of stored `<div class="forum-spoiler">` blocks.
 *
 * Locked output drops the inner HTML entirely — CSS-only hiding would still
 * leak the spoiler through View Source, quotes and scrapers.
 */
final class ForumSpoilerMarkup
{
    public const CLASS_NAME = 'forum-spoiler';

    public function rewrite(string $html, bool $unlocked, string $label, string $hint): string
    {
        if (!str_contains($html, self::CLASS_NAME)) {
            return $html;
        }

        $root = $this->loadRoot($html);
        if ($root === null) {
            return $html;
        }

        $dom = $root->ownerDocument;
        if (!$dom instanceof \DOMDocument) {
            return $html;
        }

        foreach ($this->topLevelSpoilers($root) as $spoiler) {
            $this->unwrapChrome($spoiler);
            if ($unlocked) {
                $this->open($dom, $spoiler, $label);
            } else {
                $this->lock($dom, $spoiler, $label, $hint);
            }
        }

        return $this->innerHtml($root);
    }

    /**
     * Removes spoiler inner HTML so a one-click quote cannot leak it.
     */
    public function stripForQuote(string $html): string
    {
        if (!str_contains($html, self::CLASS_NAME)) {
            return $this->plainText($html);
        }

        $stripped = $this->rewrite($html, false, '', '');

        return $this->plainText($stripped);
    }

    /**
     * A quoted rendered spoiler still carries the bar/content wrappers. Peel
     * them off so a second render does not nest chrome, and so a lock still
     * finds the original inner HTML to drop.
     */
    private function unwrapChrome(\DOMElement $spoiler): void
    {
        $content = null;
        foreach (iterator_to_array($spoiler->childNodes) as $child) {
            if ($child instanceof \DOMElement && str_contains($child->getAttribute('class'), 'forum-spoiler__content')) {
                $content = $child;
                break;
            }
        }
        if (!$content instanceof \DOMElement) {
            return;
        }

        $keep = [];
        foreach (iterator_to_array($content->childNodes) as $child) {
            $keep[] = $child;
        }
        while ($spoiler->firstChild !== null) {
            $spoiler->removeChild($spoiler->firstChild);
        }
        foreach ($keep as $child) {
            $spoiler->appendChild($child);
        }
    }

    private function open(\DOMDocument $dom, \DOMElement $spoiler, string $label): void
    {
        $this->setStateClass($spoiler, 'is-open');
        $children = [];
        foreach (iterator_to_array($spoiler->childNodes) as $child) {
            $children[] = $child;
        }

        $content = $dom->createElement('div');
        $content->setAttribute('class', 'forum-spoiler__content');
        foreach ($children as $child) {
            $content->appendChild($child);
        }

        $spoiler->appendChild($this->bar($dom, $label, false));
        $spoiler->appendChild($content);
    }

    private function lock(\DOMDocument $dom, \DOMElement $spoiler, string $label, string $hint): void
    {
        $this->setStateClass($spoiler, 'is-locked');
        while ($spoiler->firstChild !== null) {
            $spoiler->removeChild($spoiler->firstChild);
        }

        $spoiler->appendChild($this->bar($dom, $label, true));
        if ($hint !== '') {
            $p = $dom->createElement('p');
            $p->setAttribute('class', 'forum-spoiler__hint');
            $p->appendChild($dom->createTextNode($hint));
            $spoiler->appendChild($p);
        }
    }

    private function bar(\DOMDocument $dom, string $label, bool $locked): \DOMElement
    {
        $bar = $dom->createElement('div');
        $bar->setAttribute('class', 'forum-spoiler__bar');
        $icon = $dom->createElement('i');
        $icon->setAttribute('class', $locked ? 'bi bi-eye-slash' : 'bi bi-eye');
        $icon->setAttribute('aria-hidden', 'true');
        $bar->appendChild($icon);
        $bar->appendChild($dom->createTextNode(' '.$label));

        return $bar;
    }

    private function setStateClass(\DOMElement $spoiler, string $state): void
    {
        $classes = preg_split('/\s+/', trim($spoiler->getAttribute('class'))) ?: [];
        $kept = [];
        foreach ($classes as $class) {
            if ($class !== '' && $class !== 'is-open' && $class !== 'is-locked') {
                $kept[] = $class;
            }
        }
        $kept[] = $state;
        $spoiler->setAttribute('class', implode(' ', array_unique($kept)));
    }

    /**
     * @return list<\DOMElement>
     */
    private function topLevelSpoilers(\DOMElement $root): array
    {
        $xpath = new \DOMXPath($root->ownerDocument);
        $nodes = $xpath->query('.//*[contains(concat(" ", normalize-space(@class), " "), " forum-spoiler ")]', $root);
        if (!$nodes instanceof \DOMNodeList) {
            return [];
        }

        $out = [];
        foreach ($nodes as $node) {
            if (!$node instanceof \DOMElement) {
                continue;
            }
            if ($this->insideSpoiler($node, $root)) {
                continue;
            }
            $out[] = $node;
        }

        return $out;
    }

    private function insideSpoiler(\DOMElement $node, \DOMElement $root): bool
    {
        $parent = $node->parentNode;
        while ($parent instanceof \DOMElement && $parent !== $root) {
            if ($this->hasSpoilerClass($parent)) {
                return true;
            }
            $parent = $parent->parentNode;
        }

        return false;
    }

    private function hasSpoilerClass(\DOMElement $el): bool
    {
        $classes = ' '.preg_replace('/\s+/', ' ', trim($el->getAttribute('class'))).' ';

        return str_contains($classes, ' '.self::CLASS_NAME.' ');
    }

    private function loadRoot(string $html): ?\DOMElement
    {
        $previous = libxml_use_internal_errors(true);
        $dom = new \DOMDocument();
        $wrapped = '<div id="cp-forum-spoiler">'.$html.'</div>';
        $dom->loadHTML('<?xml encoding="UTF-8">'.$this->toHtmlEntities($wrapped), LIBXML_HTML_NODEFDTD | LIBXML_NOWARNING | LIBXML_NOERROR);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $root = $dom->getElementById('cp-forum-spoiler');

        return $root instanceof \DOMElement ? $root : null;
    }

    private function innerHtml(\DOMElement $root): string
    {
        $dom = $root->ownerDocument;
        if (!$dom instanceof \DOMDocument) {
            return '';
        }

        $out = '';
        foreach ($root->childNodes as $child) {
            $out .= $dom->saveHTML($child);
        }

        return $out;
    }

    private function plainText(string $html): string
    {
        $text = html_entity_decode(strip_tags($html), \ENT_QUOTES | \ENT_HTML5, 'UTF-8');
        $text = trim((string) preg_replace('/\s+/u', ' ', $text));

        return $text;
    }

    private function toHtmlEntities(string $html): string
    {
        return mb_encode_numericentity($html, [0x80, 0x10FFFF, 0, 0x10FFFF], 'UTF-8');
    }
}
