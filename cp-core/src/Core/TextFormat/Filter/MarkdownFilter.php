<?php

declare(strict_types=1);

namespace App\Core\TextFormat\Filter;

use App\Core\TextFormat\TextFilterContext;
use App\Core\TextFormat\TextFilterInterface;

/**
 * Conservative CommonMark-subset. Raw HTML is NEVER honoured (escaped first)
 * — Drupal's markdown contrib (and league/commonmark defaults) pass raw HTML
 * through, which is how a "markdown" format becomes an XSS vector.
 *
 * Supported: # headings, **bold**, *italic*, `code`, ```blocks```, lists,
 * quotes, [text](https-url). Images and inline HTML are inert text.
 */
final class MarkdownFilter implements TextFilterInterface
{
    public function id(): string
    {
        return 'markdown';
    }

    public function phases(): array
    {
        return [TextFilterContext::PHASE_OUTPUT];
    }

    public function process(string $text, TextFilterContext $context): string
    {
        $escaped = htmlspecialchars($text, \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8');

        return $this->convert($escaped);
    }

    private function convert(string $text): string
    {
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        $text = preg_replace('/^```[a-z0-9]*\n(.*?)^```/ms', '<pre><code>$1</code></pre>', $text) ?? $text;

        $lines = explode("\n", $text);
        $html = [];
        $inList = null;
        $paragraph = [];

        $flushParagraph = static function () use (&$paragraph, &$html): void {
            if ($paragraph === []) {
                return;
            }
            $html[] = '<p>'.implode("<br>\n", $paragraph).'</p>';
            $paragraph = [];
        };
        $flushList = static function () use (&$inList, &$html): void {
            if ($inList !== null) {
                $html[] = '</'.$inList.'>';
                $inList = null;
            }
        };

        foreach ($lines as $line) {
            $trimmed = trim($line);

            if ($trimmed === '') {
                $flushParagraph();
                $flushList();
                continue;
            }

            if (preg_match('/^<pre>/', $trimmed) === 1 || str_ends_with($trimmed, '</pre>')) {
                $flushParagraph();
                $flushList();
                $html[] = $line;
                continue;
            }

            if (preg_match('/^(#{1,4})\s+(.+)$/', $trimmed, $m) === 1) {
                $flushParagraph();
                $flushList();
                $level = strlen($m[1]);
                $html[] = '<h'.$level.'>'.$this->inline($m[2]).'</h'.$level.'>';
                continue;
            }

            if (preg_match('/^&gt;\s?(.*)$/', $trimmed, $m) === 1) {
                $flushParagraph();
                $flushList();
                $html[] = '<blockquote><p>'.$this->inline($m[1]).'</p></blockquote>';
                continue;
            }

            if (preg_match('/^[-*]\s+(.+)$/', $trimmed, $m) === 1) {
                $flushParagraph();
                if ($inList !== 'ul') {
                    $flushList();
                    $html[] = '<ul>';
                    $inList = 'ul';
                }
                $html[] = '<li>'.$this->inline($m[1]).'</li>';
                continue;
            }

            if (preg_match('/^\d+\.\s+(.+)$/', $trimmed, $m) === 1) {
                $flushParagraph();
                if ($inList !== 'ol') {
                    $flushList();
                    $html[] = '<ol>';
                    $inList = 'ol';
                }
                $html[] = '<li>'.$this->inline($m[1]).'</li>';
                continue;
            }

            $flushList();
            $paragraph[] = $this->inline($trimmed);
        }

        $flushParagraph();
        $flushList();

        return implode("\n", $html);
    }

    private function inline(string $text): string
    {
        $text = preg_replace('/`([^`]+)`/', '<code>$1</code>', $text) ?? $text;
        $text = preg_replace('/\*\*([^*]+)\*\*/', '<strong>$1</strong>', $text) ?? $text;
        $text = preg_replace('/\*([^*]+)\*/', '<em>$1</em>', $text) ?? $text;
        $text = preg_replace_callback(
            '/\[([^\]]+)\]\((https?:\/\/[^)\s]+)\)/',
            static function (array $m): string {
                $href = htmlspecialchars(html_entity_decode($m[2], \ENT_QUOTES, 'UTF-8'), \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8');

                return '<a href="'.$href.'" rel="nofollow noopener noreferrer">'.$m[1].'</a>';
            },
            $text,
        ) ?? $text;

        return $text;
    }
}
