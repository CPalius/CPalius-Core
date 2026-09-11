<?php

declare(strict_types=1);

namespace Modules\Forum\Service;

use App\Core\Settings\SettingsRegistry;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Render-time rewrite of post HTML: lightbox-ready images, media embeds, unfurl cards.
 */
final class ForumBodyPresenter
{
    public function __construct(
        private readonly ForumMediaEmbedder $mediaEmbedder,
        private readonly ForumLinkUnfurlService $unfurlService,
        private readonly SettingsRegistry $settingsRegistry,
        private readonly TranslatorInterface $translator,
    ) {
    }

    public function present(string $html): string
    {
        $html = trim($html);
        if ($html === '') {
            return '';
        }

        $previous = libxml_use_internal_errors(true);
        $dom = new \DOMDocument();
        $wrapped = '<div id="cp-forum-body">'.$html.'</div>';
        $dom->loadHTML('<?xml encoding="UTF-8">'.$this->toHtmlEntities($wrapped), LIBXML_HTML_NODEFDTD | LIBXML_NOWARNING | LIBXML_NOERROR);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $root = $dom->getElementById('cp-forum-body');
        if (!$root instanceof \DOMElement) {
            return $html;
        }

        $this->prepareImages($root);
        if ($this->embedsEnabled() || $this->unfurlEnabled()) {
            $this->replaceStandaloneLinks($dom, $root);
        }

        $out = '';
        foreach ($root->childNodes as $child) {
            $out .= $dom->saveHTML($child);
        }

        return $out;
    }

    private function embedsEnabled(): bool
    {
        return (bool) $this->settingsRegistry->get('forum.embeds_enabled', true);
    }

    private function unfurlEnabled(): bool
    {
        return (bool) $this->settingsRegistry->get('forum.unfurl_enabled', true);
    }

    private function prepareImages(\DOMElement $root): void
    {
        $images = [];
        foreach ($root->getElementsByTagName('img') as $img) {
            $images[] = $img;
        }
        foreach ($images as $img) {
            if (!$img instanceof \DOMElement) {
                continue;
            }
            if ($this->isInside($img, ['blockquote', 'pre', 'code'])) {
                continue;
            }
            $src = trim($img->getAttribute('src'));
            if ($src === '' || str_starts_with(strtolower($src), 'javascript:')) {
                continue;
            }
            $img->setAttribute('loading', 'lazy');
            $parent = $img->parentNode;
            if ($parent instanceof \DOMElement && strtolower($parent->tagName) === 'a') {
                $class = trim($parent->getAttribute('class').' forum-lightbox');
                $parent->setAttribute('class', $class);
                $parent->removeAttribute('target');
                if ($parent->getAttribute('href') === '' || $this->looksLikeImageHref($parent->getAttribute('href'))) {
                    $parent->setAttribute('href', $src);
                }
                continue;
            }
            $anchor = $root->ownerDocument?->createElement('a');
            if (!$anchor instanceof \DOMElement || !$parent instanceof \DOMNode) {
                continue;
            }
            $anchor->setAttribute('class', 'forum-lightbox');
            $anchor->setAttribute('href', $src);
            $parent->insertBefore($anchor, $img);
            $anchor->appendChild($img);
        }
    }

    private function looksLikeImageHref(string $href): bool
    {
        $path = (string) (parse_url($href, PHP_URL_PATH) ?? $href);

        return (bool) preg_match('/\.(jpe?g|png|gif|webp|avif|bmp)(\?|#|$)/i', $path);
    }

    private function replaceStandaloneLinks(\DOMDocument $dom, \DOMElement $root): void
    {
        $candidates = [];
        foreach (['p', 'div', 'li', 'figure'] as $tag) {
            foreach ($root->getElementsByTagName($tag) as $node) {
                $candidates[] = $node;
            }
        }

        foreach ($candidates as $node) {
            if (!$node instanceof \DOMElement || !$node->parentNode) {
                continue;
            }
            if ($this->isInside($node, ['blockquote', 'pre', 'code', 'a'])) {
                continue;
            }
            $url = $this->extractStandaloneUrl($node);
            if ($url === null || !preg_match('#^https?://#i', $url)) {
                continue;
            }

            $replacement = null;
            if ($this->embedsEnabled()) {
                $embed = $this->mediaEmbedder->embed($url);
                if ($embed !== null) {
                    $replacement = $this->fragment($dom, $embed['html']);
                }
            }
            if ($replacement === null && $this->unfurlEnabled()) {
                $replacement = $this->fragment($dom, $this->unfurlMarkup($url));
            }
            if ($replacement instanceof \DOMDocumentFragment) {
                $node->parentNode->replaceChild($replacement, $node);
            }
        }
    }

    private function unfurlMarkup(string $url): string
    {
        try {
            $cached = $this->unfurlService->findCached($url);
        } catch (\Throwable) {
            $cached = null;
        }
        if ($cached instanceof \Modules\Forum\Entity\ForumLinkPreview && $cached->isReady()) {
            return $this->cardHtml($this->unfurlService->toCardPayload($cached, $url));
        }

        return '<aside class="forum-unfurl" data-unfurl-url="'.$this->e($url).'">'
            .'<a href="'.$this->e($url).'" target="_blank" rel="noopener nofollow ugc">'.$this->e($url).'</a>'
            .'</aside>';
    }

    /**
     * @param array{ok: bool, url: string, title: ?string, description: ?string, image: ?string, siteName: ?string, favicon: ?string, host: string} $card
     */
    public function cardHtml(array $card): string
    {
        $url = $card['url'];
        $title = $card['title'] ?: $card['host'] ?: $url;
        $badge = $this->translator->trans('forum.unfurl.external');
        $image = '';
        if (!empty($card['image'])) {
            $image = '<span class="forum-unfurl-card__media"><img src="'.$this->e((string) $card['image']).'" alt="" loading="lazy"></span>';
        }
        $favicon = '';
        if (!empty($card['favicon'])) {
            $favicon = '<img class="forum-unfurl-card__favicon" src="'.$this->e((string) $card['favicon']).'" alt="" width="16" height="16" loading="lazy">';
        }
        $desc = '';
        if (!empty($card['description'])) {
            $desc = '<p class="forum-unfurl-card__desc">'.$this->e((string) $card['description']).'</p>';
        }

        return '<aside class="forum-unfurl-card">'
            .'<a class="forum-unfurl-card__link" href="'.$this->e($url).'" target="_blank" rel="noopener nofollow ugc">'
            .$image
            .'<span class="forum-unfurl-card__body">'
            .'<span class="forum-unfurl-card__site">'.$favicon.$this->e((string) ($card['siteName'] ?: $card['host'])).'</span>'
            .'<strong class="forum-unfurl-card__title">'.$this->e($title).'</strong>'
            .$desc
            .'<span class="forum-unfurl-card__badge">'.$this->e($badge).'</span>'
            .'</span></a></aside>';
    }

    private function extractStandaloneUrl(\DOMElement $node): ?string
    {
        $anchor = $this->standaloneAnchor($node);
        if ($anchor instanceof \DOMElement) {
            if (str_contains($anchor->getAttribute('class'), 'forum-lightbox')) {
                return null;
            }
            $href = trim($anchor->getAttribute('href'));

            return $href !== '' ? $href : null;
        }

        $text = '';
        foreach ($node->childNodes as $child) {
            if ($child instanceof \DOMText) {
                $text .= $child->textContent;
                continue;
            }
            if ($child instanceof \DOMElement && strtolower($child->tagName) === 'br') {
                continue;
            }

            return null;
        }
        $text = trim($text);
        if (preg_match('#^https?://[^\s<>]+$#', $text)) {
            return $text;
        }

        return null;
    }

    private function standaloneAnchor(\DOMElement $node): ?\DOMElement
    {
        $anchor = null;
        foreach ($node->childNodes as $child) {
            if ($child instanceof \DOMText) {
                if (trim($child->textContent) !== '') {
                    return null;
                }
                continue;
            }
            if (!$child instanceof \DOMElement) {
                return null;
            }
            $tag = strtolower($child->tagName);
            if ($tag === 'br') {
                continue;
            }
            if ($tag === 'a' && $anchor === null) {
                $anchor = $child;
                continue;
            }

            return null;
        }

        return $anchor;
    }

    private function isInside(\DOMNode $node, array $tags): bool
    {
        $parent = $node->parentNode;
        while ($parent instanceof \DOMElement) {
            if (\in_array(strtolower($parent->tagName), $tags, true)) {
                return true;
            }
            $parent = $parent->parentNode;
        }

        return false;
    }

    private function fragment(\DOMDocument $dom, string $html): ?\DOMDocumentFragment
    {
        $tmp = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $tmp->loadHTML('<?xml encoding="UTF-8"><div id="cp-frag">'.$this->toHtmlEntities($html).'</div>', LIBXML_HTML_NODEFDTD | LIBXML_NOWARNING | LIBXML_NOERROR);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        $src = $tmp->getElementById('cp-frag');
        $frag = $dom->createDocumentFragment();
        if (!$src instanceof \DOMElement) {
            return null;
        }
        foreach (iterator_to_array($src->childNodes) as $child) {
            $imported = $dom->importNode($child, true);
            if ($imported instanceof \DOMNode) {
                $frag->appendChild($imported);
            }
        }

        return $frag->hasChildNodes() ? $frag : null;
    }

    private function toHtmlEntities(string $html): string
    {
        return mb_encode_numericentity($html, [0x80, 0x10FFFF, 0, 0x10FFFF], 'UTF-8');
    }

    private function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
