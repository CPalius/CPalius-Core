<?php

declare(strict_types=1);

namespace App\Core\TextFormat\Filter;

use App\Core\TextFormat\TextFilterContext;
use App\Core\TextFormat\TextFilterInterface;
use Symfony\Component\HtmlSanitizer\HtmlSanitizer;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerConfig;

/**
 * Format allowlist + a hardcoded deny-list no format can override.
 *
 * Drupal Full HTML can be configured to keep `<script>`; WordPress
 * `unfiltered_html` is the same class of hole. CPalius drops script/style/
 * form/iframe-on-save/event handlers even for `full_html` — media iframes
 * are produced later by MediaEmbedFilter on OUTPUT from allowlisted URLs.
 */
final class HtmlRestrictFilter implements TextFilterInterface
{
    /** @var list<string> */
    private const NEVER_ALLOW = [
        'script', 'style', 'iframe', 'object', 'embed', 'applet', 'param',
        'form', 'input', 'button', 'select', 'option', 'optgroup', 'textarea',
        'label', 'fieldset', 'legend', 'datalist', 'output',
        'base', 'link', 'meta', 'frame', 'frameset', 'html', 'head', 'body',
        'noscript', 'template',
    ];

    public function id(): string
    {
        return 'html_restrict';
    }

    public function phases(): array
    {
        return [TextFilterContext::PHASE_STORAGE, TextFilterContext::PHASE_OUTPUT];
    }

    public function process(string $text, TextFilterContext $context): string
    {
        $allow = $context->settings['allow_elements'] ?? [];
        if (!\is_array($allow) || $allow === []) {
            return htmlspecialchars($text, \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8');
        }

        $config = (new HtmlSanitizerConfig())
            ->allowLinkSchemes(['http', 'https', 'mailto'])
            ->allowRelativeLinks()
            ->allowRelativeMedias()
            ->dropAttribute('style', '*');

        foreach ($allow as $tag => $attrs) {
            if (!\is_string($tag) || $tag === '' || \in_array(strtolower($tag), self::NEVER_ALLOW, true)) {
                continue;
            }
            $allowedAttrs = [];
            if (\is_array($attrs)) {
                foreach ($attrs as $attr) {
                    if (\is_string($attr) && $attr !== '' && !str_starts_with(strtolower($attr), 'on')) {
                        $allowedAttrs[] = $attr;
                    }
                }
            }
            $config = $config->allowElement(strtolower($tag), $allowedAttrs);
        }

        $config = $config->allowAttribute('class', '*');

        foreach (self::NEVER_ALLOW as $blocked) {
            $config = $config->dropElement($blocked);
        }

        return (new HtmlSanitizer($config))->sanitize($text);
    }
}
