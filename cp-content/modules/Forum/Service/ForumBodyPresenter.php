<?php

declare(strict_types=1);

namespace Modules\Forum\Service;

use App\Core\Settings\SettingsRegistry;
use App\Entity\User;
use App\Repository\UserRepository;
use Modules\Importer\Markup\BbCodeConverter;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Render-time rewrite of post HTML: lightbox-ready images, media embeds, unfurl
 * cards, @mention links and #N post references.
 *
 * Mentions and post references are resolved at render time rather than at save
 * time so that a post written before this feature existed, or typed by hand
 * without the composer's autocomplete, still comes out linked.
 */
final class ForumBodyPresenter
{
    /** Same shape ForumMentionParser recognises, so notifying and linking agree. */
    private const MENTION_PATTERN = '/@([a-zA-Z0-9_\-.]{2,32})/u';

    /** "#12" — a post's position in the topic, which is the number readers see. */
    private const POST_REF_PATTERN = '/(?<![\w#])#(\d{1,4})(?!\w)/u';

    /** Text in here carries no new links: code is code, and anchors cannot nest. */
    private const OPAQUE_TAGS = ['a', 'pre', 'code', 'script', 'style'];

    /** @var array<string, User|false> Per-request memo; one body may name someone many times. */
    private array $userMemo = [];

    public function __construct(
        private readonly ForumMediaEmbedder $mediaEmbedder,
        private readonly ForumLinkUnfurlService $unfurlService,
        private readonly SettingsRegistry $settingsRegistry,
        private readonly TranslatorInterface $translator,
        private readonly UserRepository $userRepository,
        private readonly UrlGeneratorInterface $urlGenerator,
        private readonly ForumSmilieCatalog $smilies,
    ) {
    }

    /**
     * @param int|null $topicId topic the post belongs to; without it "#3" stays
     *                          plain text, because a reference with no
     *                          conversation to resolve against would point at
     *                          some unrelated post
     */
    public function present(string $html, ?int $topicId = null): string
    {
        $html = trim($html);
        if ($html === '') {
            return '';
        }

        // Already-imported XenForo / MyBB posts still carry tags the first
        // converter did not know ([USER], [IMG width="…"]). Rewrite those
        // without re-escaping the HTML that did convert.
        $html = (new BbCodeConverter())->rewriteInHtml($html);

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

        $this->replaceSmilies($dom, $root);
        $this->prepareImages($root);
        $this->linkifyTextNodes($dom, $root, $topicId);
        if ($this->embedsEnabled() || $this->unfurlEnabled()) {
            $this->replaceStandaloneLinks($dom, $root);
        }

        $out = '';
        foreach ($root->childNodes as $child) {
            $out .= $dom->saveHTML($child);
        }

        return $out;
    }

    /**
     * XenForo (and the composer picker) store ":)" in the post. Swap it here
     * so imported threads match the old board without rewriting every row.
     */
    private function replaceSmilies(\DOMDocument $dom, \DOMElement $root): void
    {
        $xpath = new \DOMXPath($dom);
        $nodes = $xpath->query('.//text()', $root);

        if (!$nodes instanceof \DOMNodeList) {
            return;
        }

        $targets = [];
        foreach ($nodes as $node) {
            if ($node instanceof \DOMText && !$this->isInside($node, self::OPAQUE_TAGS)) {
                $targets[] = $node;
            }
        }

        foreach ($targets as $node) {
            $html = $this->smilies->replace($node->textContent);

            if ($html === null) {
                continue;
            }

            $fragment = $this->fragment($dom, $html);
            if ($fragment instanceof \DOMDocumentFragment && $node->parentNode instanceof \DOMNode) {
                $node->parentNode->replaceChild($fragment, $node);
            }
        }
    }

    /**
     * Turns "@name" and "#12" inside ordinary text into links.
     *
     * Text nodes only, and never inside an anchor or a code block — replacing
     * on the raw HTML string instead would happily rewrite an href, a class
     * name or the contents of a code sample, which is exactly the bug this
     * whole DOM-walking presenter exists to avoid.
     */
    private function linkifyTextNodes(\DOMDocument $dom, \DOMElement $root, ?int $topicId): void
    {
        $xpath = new \DOMXPath($dom);
        $nodes = $xpath->query('.//text()', $root);

        if (!$nodes instanceof \DOMNodeList) {
            return;
        }

        // Materialised first: replacing a node while iterating a live list
        // makes the iterator skip siblings.
        $targets = [];
        foreach ($nodes as $node) {
            if ($node instanceof \DOMText && !$this->isInside($node, self::OPAQUE_TAGS)) {
                $targets[] = $node;
            }
        }

        foreach ($targets as $node) {
            $html = $this->linkifyText($node->textContent, $topicId);

            if ($html === null) {
                continue;
            }

            $fragment = $this->fragment($dom, $html);
            if ($fragment instanceof \DOMDocumentFragment && $node->parentNode instanceof \DOMNode) {
                $node->parentNode->replaceChild($fragment, $node);
            }
        }
    }

    /**
     * @return string|null HTML for the rewritten text, or null when nothing matched
     */
    private function linkifyText(string $text, ?int $topicId): ?string
    {
        if (!str_contains($text, '@') && !($topicId !== null && str_contains($text, '#'))) {
            return null;
        }

        $changed = false;

        $html = preg_replace_callback(
            self::MENTION_PATTERN,
            function (array $m) use (&$changed): string {
                $user = $this->resolveUser($m[1]);

                if (!$user instanceof User) {
                    // An unknown name is not a mention, it is just text that
                    // happens to start with @ — leave it alone.
                    return $this->e($m[0]);
                }

                $changed = true;

                return '<a class="forum-mention" href="'.$this->e($this->profileUrl($user)).'"'
                    .' data-mention="'.$this->e($user->getProfileSlug()).'">@'.$this->e($m[1]).'</a>';
            },
            $this->e($text),
        ) ?? $this->e($text);

        if ($topicId !== null) {
            $html = preg_replace_callback(
                self::POST_REF_PATTERN,
                function (array $m) use (&$changed, $topicId): string {
                    $changed = true;

                    return '<a class="forum-postref" href="#postnum-'.$this->e($m[1]).'"'
                        .' data-post-ref="'.$this->e($m[1]).'"'
                        .' data-topic-ref="'.$topicId.'">#'.$this->e($m[1]).'</a>';
                },
                $html,
            ) ?? $html;
        }

        return $changed ? $html : null;
    }

    private function resolveUser(string $username): ?User
    {
        $key = mb_strtolower($username);

        if (!\array_key_exists($key, $this->userMemo)) {
            $this->userMemo[$key] = $this->userRepository->findOneByUsername($username) ?? false;
        }

        $user = $this->userMemo[$key];

        return $user instanceof User ? $user : null;
    }

    private function profileUrl(User $user): string
    {
        try {
            return $this->urlGenerator->generate('forum_profile', ['username' => $user->getProfileSlug()]);
        } catch (\Throwable) {
            // Forum routes gone (module disabled mid-render) — a mention that
            // cannot link is still a name worth showing.
            return '#';
        }
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
            if (str_contains($img->getAttribute('class'), 'forum-smilie')) {
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
            if (str_contains($node->getAttribute('class'), 'forum-spoiler')) {
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
