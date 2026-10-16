<?php

declare(strict_types=1);

namespace App\Core\Homepage;

use Symfony\Component\HtmlSanitizer\HtmlSanitizer;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerConfig;

/**
 * What a hand-written homepage is allowed to contain.
 *
 * Two layers, and only one of them is a promise:
 *
 *  1. THE PROMISE — the page is served with a Content-Security-Policy that gives it
 *     an opaque origin (sandbox without allow-same-origin), no network access
 *     (connect-src 'none'), no forms, no eval and only nonced script. Whatever
 *     the script does, it cannot read a cookie, call the panel or talk to a
 *     server. That is enforced by the browser, not by anything in this class.
 *
 *  2. THE ADVICE — this class. It sanitises the markup, rejects css that loads
 *     code, and refuses script that visibly tries to do what layer 1 forbids, so
 *     the editor can say why instead of the page failing silently. A deny-list
 *     can always be worked around; that is why it is not the defence.
 */
final class HomepageHtmlGuard
{
    /**
     * JS patterns that the sandbox would block anyway. Reported at save time so
     * the author learns it from the editor and not from a blank page.
     *
     * @var array<string, string> regex => why
     */
    private const JS_RULES = [
        '/\beval\s*\(/' => 'eval() is not allowed.',
        '/\bnew\s+Function\b|\bFunction\s*\(/' => 'Function() is not allowed.',
        '/\bdocument\s*\.\s*cookie\b/' => 'Cookies cannot be read or written.',
        '/\b(localStorage|sessionStorage|indexedDB|caches)\b/' => 'Browser storage is not available to the homepage.',
        '/\b(XMLHttpRequest|WebSocket|EventSource|sendBeacon|importScripts)\b/' => 'Network access is not allowed.',
        '/\bfetch\s*\(/' => 'fetch() is not allowed: the page cannot make network requests.',
        '/\bimport\s*\(|^\s*import\s/m' => 'Dynamic and module imports are not allowed.',
        '/\bdocument\s*\.\s*write(ln)?\s*\(/' => 'document.write() is not allowed.',
        '/\.\s*(innerHTML|outerHTML)\s*=|insertAdjacentHTML|createContextualFragment|\bsrcdoc\b/' => 'Writing HTML from script is not allowed; build elements with textContent / createElement.',
        '/createElement\s*\(\s*[\'"`]\s*(script|iframe|object|embed|link|base|meta)\b/i' => 'Creating script, iframe, object, link, base or meta elements is not allowed.',
        '/\b(window|self|globalThis|top|parent|frames)\s*\[/' => 'Computed access to the global object is not allowed.',
        '/\b(window|document|self|top|parent)\s*\.\s*location\s*(\.\s*(href|assign|replace))?\s*=[^=]|\blocation\s*\.\s*(assign|replace)\s*\(|(^|[^.\w])location\s*(\.\s*href)?\s*=[^=]/m' => 'Redirecting the visitor from script is not allowed; use a normal link.',
        '/\b(top|parent)\s*\.\s*\w/' => 'The page cannot reach its parent or top window.',
        '/\bpostMessage\b/' => 'postMessage is not allowed.',
        '/\b(setTimeout|setInterval)\s*\(\s*[\'"`]/' => 'Passing a string to setTimeout/setInterval is not allowed.',
        '/<\/script/i' => 'The sequence </script cannot appear inside the script.',
    ];

    /** @var array<string, string> regex => why */
    private const CSS_RULES = [
        '/@import\b/i' => '@import is not allowed: stylesheets cannot be pulled from elsewhere.',
        '/expression\s*\(|behaviou?r\s*:|-moz-binding/i' => 'Scriptable css (expression, behavior, -moz-binding) is not allowed.',
        '/javascript\s*:|vbscript\s*:/i' => 'Script URLs in css are not allowed.',
        '/<\s*\/?\s*(style|script)/i' => 'HTML tags cannot appear inside the css.',
    ];

    private ?HtmlSanitizer $page = null;

    /**
     * @param array{html: string, css: string, js: string} $parts
     *
     * @return array{html: string, css: string, js: string, errors: list<string>, notes: list<string>}
     */
    public function check(array $parts): array
    {
        $errors = [];
        $notes = [];

        $html = (string) ($parts['html'] ?? '');
        $clean = $this->sanitizer()->sanitize($html);
        if ($this->squash($clean) !== $this->squash($html)) {
            $notes[] = 'Some markup was removed for safety (scripts, event handlers, forms, unknown tags or unsafe addresses). Put script in the JS box.';
        }

        $css = (string) ($parts['css'] ?? '');
        foreach (self::CSS_RULES as $pattern => $why) {
            if (preg_match($pattern, $css) === 1) {
                $errors[] = 'CSS: '.$why;
            }
        }
        // url() may point at the site itself, an https image or an inline image, nothing else.
        if (preg_match_all('/url\(\s*([\'"]?)(.*?)\1\s*\)/i', $css, $m) > 0) {
            foreach ($m[2] as $target) {
                $target = trim($target);
                if ($target !== '' && preg_match('~^(https://|/(?!/)|data:image/(png|jpe?g|gif|webp|svg\+xml|avif);|#)~i', $target) !== 1) {
                    $errors[] = 'CSS: url('.mb_substr($target, 0, 40).') is not an allowed address.';
                }
            }
        }

        $js = (string) ($parts['js'] ?? '');
        foreach (self::JS_RULES as $pattern => $why) {
            if (preg_match($pattern, $js) === 1) {
                $errors[] = 'JS: '.$why;
            }
        }
        if ($js !== '') {
            $notes[] = 'The script runs in an isolated sandbox: no cookies, no storage, no network. It can change the page and react to clicks.';
        }

        return [
            'html' => $clean,
            'css' => $css,
            'js' => $js,
            'errors' => array_values(array_unique($errors)),
            'notes' => $notes,
        ];
    }

    /** Whitespace and attribute-quote differences are not "removed markup". */
    private function squash(string $html): string
    {
        $html = html_entity_decode($html, \ENT_QUOTES | \ENT_HTML5, 'UTF-8');

        return strtolower(preg_replace('/[\s"\'\/]+/', '', $html) ?? '');
    }

    private function sanitizer(): HtmlSanitizer
    {
        if ($this->page !== null) {
            return $this->page;
        }

        $global = ['class', 'id', 'style', 'title', 'lang', 'dir', 'role', 'aria-label', 'aria-hidden', 'aria-expanded', 'aria-controls', 'aria-labelledby', 'data-action', 'data-target', 'data-id'];
        $config = (new HtmlSanitizerConfig())
            ->allowLinkSchemes(['https', 'http', 'mailto', 'tel'])
            ->allowMediaSchemes(['https'])
            ->allowRelativeLinks()
            ->allowRelativeMedias()
            ->forceAttribute('a', 'rel', 'noopener noreferrer');

        $tags = [
            'div', 'section', 'header', 'footer', 'nav', 'main', 'article', 'aside', 'figure', 'figcaption',
            'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'p', 'span', 'strong', 'em', 'b', 'i', 'u', 's', 'small', 'mark',
            'sub', 'sup', 'br', 'hr', 'blockquote', 'pre', 'code', 'kbd', 'ul', 'ol', 'li', 'dl', 'dt', 'dd',
            'table', 'thead', 'tbody', 'tfoot', 'tr', 'th', 'td', 'caption', 'details', 'summary', 'address', 'time', 'abbr',
        ];
        foreach ($tags as $tag) {
            $config = $config->allowElement($tag, $global);
        }
        $config = $config
            ->allowElement('a', array_merge($global, ['href', 'target']))
            ->allowElement('img', array_merge($global, ['src', 'alt', 'width', 'height', 'loading']))
            ->allowElement('picture', $global)
            ->allowElement('source', ['srcset', 'media', 'type'])
            ->allowElement('video', array_merge($global, ['src', 'poster', 'controls', 'muted', 'loop', 'playsinline', 'width', 'height']))
            ->allowElement('audio', array_merge($global, ['src', 'controls', 'loop']))
            ->allowElement('button', array_merge($global, ['type']));

        return $this->page = new HtmlSanitizer($config);
    }
}
