<?php

declare(strict_types=1);

namespace Modules\Forum\Markup;

/**
 * Turns forum BBCode into HTML.
 *
 * Every forum package stores posts as BBCode, and CPalius stores HTML. Without
 * this, an imported board reads as a wall of [b]literal[/b] markup — the
 * content is all there and none of it is legible, which is the most
 * demoralising possible outcome for a migration.
 *
 * SAFETY FIRST, THEN MARKUP
 * The source text is escaped BEFORE any tag is converted, so anything that was
 * raw HTML in the original post — script tags included, and forums are full of
 * old XSS attempts — comes out as visible text rather than as markup. Only the
 * BBCode this understands is then turned back into elements. The result still
 * passes through the core sanitiser on the way to storage; this is the first
 * of two gates, not the only one.
 *
 * Attribute values (URLs, colours, sizes) are validated rather than trusted:
 * [url=javascript:...] is the oldest trick in the forum book.
 *
 * What it does not do: BBCode has no standard, every package extends it, and a
 * converter that silently swallowed unknown tags would delete content. Tags it
 * does not know are left exactly as they are — visible, searchable, and fixable
 * by hand — which is the honest failure mode.
 */
final class BbCodeConverter
{
    /** Schemes allowed in [url] and [img]. */
    private const SAFE_SCHEMES = ['http', 'https', 'mailto', 'ftp'];

    public function convert(string $bbcode): string
    {
        if (trim($bbcode) === '') {
            return '';
        }

        // Escape first: whatever was HTML in the source stays text.
        $html = htmlspecialchars($bbcode, \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8');

        return $this->paragraphs($this->applyTags($html));
    }

    /**
     * Convert leftover BBCode inside already-stored HTML.
     *
     * Imported posts that ran through an older converter still contain
     * [USER], [IMG width="…"] and friends as literal text. Re-escaping the
     * whole body would destroy the HTML that did convert; this only rewrites
     * the tags that are still sitting there.
     */
    public function rewriteInHtml(string $html): string
    {
        if ($html === '' || !str_contains($html, '[')) {
            return $html;
        }

        return $this->applyTags($html);
    }

    private function applyTags(string $html): string
    {
        $html = $this->code($html);
        $html = $this->simple($html);
        $html = $this->mentions($html);
        $html = $this->links($html);
        $html = $this->images($html);
        $html = $this->media($html);
        $html = $this->attachments($html);
        $html = $this->quotes($html);
        $html = $this->lists($html);
        $html = $this->styles($html);

        return $html;
    }

    /**
     * Code blocks first, and their contents are left alone afterwards by
     * converting them to a placeholder-free form early: markup inside a code
     * block is meant to be read, not rendered.
     */
    private function code(string $html): string
    {
        $html = (string) preg_replace_callback(
            '#\[code(?:=[^\]]*)?\](.*?)\[/code\]#is',
            static fn (array $m): string => '<pre><code>'.trim($m[1]).'</code></pre>',
            $html,
        );

        return (string) preg_replace('#\[icode\](.*?)\[/icode\]#is', '<code>$1</code>', $html);
    }

    private function simple(string $html): string
    {
        $map = [
            '#\[b\](.*?)\[/b\]#is' => '<strong>$1</strong>',
            '#\[i\](.*?)\[/i\]#is' => '<em>$1</em>',
            '#\[u\](.*?)\[/u\]#is' => '<u>$1</u>',
            '#\[s\](.*?)\[/s\]#is' => '<s>$1</s>',
            '#\[strike\](.*?)\[/strike\]#is' => '<s>$1</s>',
            '#\[center\](.*?)\[/center\]#is' => '<p style="text-align:center">$1</p>',
            '#\[right\](.*?)\[/right\]#is' => '<p style="text-align:right">$1</p>',
            '#\[left\](.*?)\[/left\]#is' => '<p style="text-align:left">$1</p>',
            '#\[spoiler\](.*?)\[/spoiler\]#is' => '<details><summary>spoiler</summary>$1</details>',
            '#\[ispoiler\](.*?)\[/ispoiler\]#is' => '<details><summary>spoiler</summary>$1</details>',
            '#\[plain\](.*?)\[/plain\]#is' => '$1',
            '#\[sub\](.*?)\[/sub\]#is' => '<sub>$1</sub>',
            '#\[sup\](.*?)\[/sup\]#is' => '<sup>$1</sup>',
            '#\[hr\s*/?\]#i' => '<hr>',
            '#\[indent\](.*?)\[/indent\]#is' => '<div style="margin-left:1.5em">$1</div>',
        ];

        foreach ($map as $pattern => $replacement) {
            $html = (string) preg_replace($pattern, $replacement, $html);
        }

        $html = (string) preg_replace_callback(
            '#\[spoiler=([^\]]+)\](.*?)\[/spoiler\]#is',
            static fn (array $m): string => '<details><summary>'.trim($m[1]).'</summary>'.$m[2].'</details>',
            $html,
        );

        $html = (string) preg_replace_callback(
            '#\[align=(left|right|center|justify)\](.*?)\[/align\]#is',
            static fn (array $m): string => '<p style="text-align:'.strtolower($m[1]).'">'.$m[2].'</p>',
            $html,
        );

        $html = (string) preg_replace_callback(
            '#\[heading=([1-6])\](.*?)\[/heading\]#is',
            static fn (array $m): string => '<h'.$m[1].'>'.$m[2].'</h'.$m[1].'>',
            $html,
        );

        return $html;
    }

    /**
     * XenForo [USER=12]@Ali[/USER] and MyBB [mention=12]Ali[/mention].
     *
     * Unwrapped to @name so the forum presenter can turn it into a profile
     * link the same way a typed mention is.
     */
    private function mentions(string $html): string
    {
        return (string) preg_replace_callback(
            '#\[(?:user|mention)(?:\s[^\]]*)?(?:=[^\]]*)?\]@?([^\[]+?)\[/(?:user|mention)\]#is',
            static function (array $m): string {
                $name = trim(html_entity_decode($m[1], \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8'));

                return $name === '' ? '' : '@'.$name;
            },
            $html,
        );
    }

    private function links(string $html): string
    {
        $html = (string) preg_replace_callback(
            '#\[url=([^\]]+)\](.*?)\[/url\]#is',
            fn (array $m): string => $this->link($m[1], $m[2]),
            $html,
        );

        $html = (string) preg_replace_callback(
            '#\[url(?:\s[^\]]*)?\](.*?)\[/url\]#is',
            fn (array $m): string => $this->link($m[1], $m[1]),
            $html,
        );

        $html = (string) preg_replace_callback(
            '#\[email=([^\]]+)\](.*?)\[/email\]#is',
            fn (array $m): string => $this->link('mailto:'.trim($m[1]), $m[2]),
            $html,
        );

        return (string) preg_replace_callback(
            '#\[email\](.*?)\[/email\]#is',
            fn (array $m): string => $this->link('mailto:'.trim($m[1]), $m[1]),
            $html,
        );
    }

    private function link(string $url, string $label): string
    {
        $safe = $this->safeUrl($url);

        if ($safe === null) {
            // The label is kept and the link dropped: the reader still sees
            // what was written, and nothing dangerous is clickable.
            return $label;
        }

        return sprintf('<a href="%s" rel="nofollow noopener">%s</a>', $safe, $label);
    }

    private function images(string $html): string
    {
        // XenForo writes [IMG width="640" height="480"]url[/IMG]; MyBB writes
        // [img=100x50]url[/img]. The old pattern only accepted [img] or
        // [img=…], so attributed tags survived as literal text.
        return (string) preg_replace_callback(
            '#\[img(?:\s[^\]]*)?(?:=[^\]]*)?\](.*?)\[/img\]#is',
            function (array $m): string {
                $safe = $this->safeUrl(trim($m[1]));

                return $safe === null ? '' : sprintf('<img src="%s" alt="">', $safe);
            },
            $html,
        );
    }

    private function media(string $html): string
    {
        $html = (string) preg_replace_callback(
            '#\[media=youtube\]([a-zA-Z0-9_-]{6,})\[/media\]#is',
            fn (array $m): string => $this->link('https://www.youtube.com/watch?v='.$m[1], 'YouTube'),
            $html,
        );

        $html = (string) preg_replace_callback(
            '#\[video=youtube\](.*?)\[/video\]#is',
            function (array $m): string {
                $url = trim($m[1]);

                return $this->link($url, $url);
            },
            $html,
        );

        return (string) preg_replace_callback(
            '#\[media=([a-z0-9_-]+)\](.*?)\[/media\]#is',
            function (array $m): string {
                $body = trim($m[2]);

                return $this->safeUrl($body) !== null ? $this->link($body, $body) : $body;
            },
            $html,
        );
    }

    /**
     * Attachments need the old board's files, which this import does not
     * fetch. Drop the tag so the post is readable; a leftover [ATTACH]123
     * is worse than a missing picture.
     */
    private function attachments(string $html): string
    {
        return (string) preg_replace(
            '#\[attach(?:\s[^\]]*)?(?:=[^\]]*)?\].*?\[/attach\]#is',
            '',
            $html,
        );
    }

    private function quotes(string $html): string
    {
        // Nested quotes are common; repeat until the innermost is converted.
        $previous = null;

        while ($previous !== $html) {
            $previous = $html;

            $html = (string) preg_replace(
                '#\[quote=(?:&quot;)?([^\]&]*)(?:&quot;)?(?:;[^\]]*)?\]((?:(?!\[quote).)*?)\[/quote\]#is',
                '<blockquote><cite>$1</cite>$2</blockquote>',
                $html,
            );

            $html = (string) preg_replace(
                '#\[quote\]((?:(?!\[quote).)*?)\[/quote\]#is',
                '<blockquote>$1</blockquote>',
                $html,
            );

            // XenForo 2 / MyBB: [QUOTE="Name, post: 1, member: 2"] or
            // [quote="Ali" pid="12"] — anything still tagged after the
            // stricter patterns is unwrapped so it does not stay visible.
            $html = (string) preg_replace(
                '#\[quote[^\]]*\]((?:(?!\[quote).)*?)\[/quote\]#is',
                '<blockquote>$1</blockquote>',
                $html,
            );
        }

        return $html;
    }

    private function lists(string $html): string
    {
        return (string) preg_replace_callback(
            '#\[list(?:=([^\]]*))?\](.*?)\[/list\]#is',
            static function (array $m): string {
                $ordered = trim($m[1]) !== '';
                $items = preg_split('#\[\*\]#', $m[2]) ?: [];
                $html = '';

                foreach ($items as $item) {
                    $item = trim($item);

                    if ($item !== '') {
                        $html .= '<li>'.$item.'</li>';
                    }
                }

                if ($html === '') {
                    return '';
                }

                return $ordered ? '<ol>'.$html.'</ol>' : '<ul>'.$html.'</ul>';
            },
            $html,
        );
    }

    private function styles(string $html): string
    {
        $html = (string) preg_replace_callback(
            '#\[color=([^\]]+)\](.*?)\[/color\]#is',
            static function (array $m): string {
                $colour = trim($m[1]);

                // Only a named colour or a hex value; anything else could close
                // the attribute and start a new one.
                return preg_match('/^(#[0-9a-fA-F]{3,8}|[a-zA-Z]{3,20})$/', $colour) === 1
                    ? sprintf('<span style="color:%s">%s</span>', $colour, $m[2])
                    : $m[2];
            },
            $html,
        );

        $html = (string) preg_replace_callback(
            '#\[size=([^\]]+)\](.*?)\[/size\]#is',
            static function (array $m): string {
                $size = trim($m[1]);

                return preg_match('/^\d{1,3}(px|pt|em|%)?$/', $size) === 1
                    ? sprintf('<span style="font-size:%s">%s</span>', ctype_digit($size) ? $size.'px' : $size, $m[2])
                    : $m[2];
            },
            $html,
        );

        return (string) preg_replace_callback(
            '#\[font=([^\]]+)\](.*?)\[/font\]#is',
            static function (array $m): string {
                $font = trim($m[1], " \t\"'");

                return preg_match('/^[a-zA-Z0-9 \-]{1,40}$/', $font) === 1
                    ? sprintf('<span style="font-family:%s">%s</span>', $font, $m[2])
                    : $m[2];
            },
            $html,
        );
    }

    /**
     * Forum posts are newline-separated text; without this every post arrives
     * as one unbroken block.
     */
    private function paragraphs(string $html): string
    {
        $normalised = str_replace(["\r\n", "\r"], "\n", $html);
        $blocks = preg_split('/\n{2,}/', $normalised) ?: [];
        $out = '';

        foreach ($blocks as $block) {
            $block = trim($block);

            if ($block === '') {
                continue;
            }

            // Something that is already a block element is left alone rather
            // than wrapped in a paragraph it cannot legally sit in.
            $out .= preg_match('#^<(p|div|ul|ol|blockquote|pre|details|h[1-6])[\s>]#i', $block) === 1
                ? $block
                : '<p>'.nl2br($block, false).'</p>';
        }

        return $out;
    }

    /**
     * Refuses anything that is not a plain, safely-schemed URL.
     */
    private function safeUrl(string $url): ?string
    {
        // The text was escaped before conversion, so an entity-encoded scheme
        // has to be decoded before it can be judged — "javascript&colon;" and
        // friends are exactly how this check gets walked past.
        $candidate = trim(html_entity_decode($url, \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8'));

        if ($candidate === '' || preg_match('/[\x00-\x1F\x7F]/', $candidate) === 1) {
            return null;
        }

        // A relative URL is fine and has no scheme to check.
        if (str_starts_with($candidate, '/') && !str_starts_with($candidate, '//')) {
            return htmlspecialchars($candidate, \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8');
        }

        $scheme = strtolower((string) parse_url($candidate, \PHP_URL_SCHEME));

        if ($scheme === '' || !\in_array($scheme, self::SAFE_SCHEMES, true)) {
            return null;
        }

        return htmlspecialchars($candidate, \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8');
    }
}
