<?php

declare(strict_types=1);

namespace App\Core\TextFormat;

use App\Core\TextFormat\Filter\HtmlRestrictFilter;
use App\Core\TextFormat\Filter\MediaEmbedFilter;

/**
 * Runs a named format's filter chain. Storage and output are separate phases
 * so the DB is never an XSS warehouse (Drupal's well-known Filter API hole:
 * disable one output filter, stored markup is suddenly live).
 *
 * Failsafe HtmlRestrictFilter always runs on output after the configured
 * chain except MediaEmbedFilter, which is applied LAST so the deny-list's
 * iframe drop cannot strip the sandboxed players we just built.
 */
final class TextFormatProcessor
{
    /**
     * @param iterable<TextFilterInterface> $filters
     */
    public function __construct(
        private readonly iterable $filters,
        private readonly TextFormatRegistry $formats,
        private readonly HtmlRestrictFilter $failsafe,
    ) {
    }

    public function sanitizeForStorage(string $text, string $formatId): string
    {
        return $this->run($text, $formatId, TextFilterContext::PHASE_STORAGE, [], skip: []);
    }

    /**
     * @param array<string, mixed> $tokenContext
     */
    public function processForDisplay(string $text, string $formatId, array $tokenContext = []): string
    {
        $format = $this->formats->getOrFallback($formatId);
        $out = $this->run($text, $formatId, TextFilterContext::PHASE_OUTPUT, $tokenContext, skip: [MediaEmbedFilter::ID]);
        $out = $this->failsafe->process($out, new TextFilterContext(
            $format->id,
            TextFilterContext::PHASE_OUTPUT,
            $this->restrictSettings($format),
        ));

        return $this->run($out, $formatId, TextFilterContext::PHASE_OUTPUT, $tokenContext, only: [MediaEmbedFilter::ID]);
    }

    /**
     * @param array<string, mixed> $tokenContext
     * @param list<string>         $skip
     * @param list<string>|null    $only
     */
    private function run(string $text, string $formatId, string $phase, array $tokenContext, array $skip = [], ?array $only = null): string
    {
        $format = $this->formats->getOrFallback($formatId);
        $byId = [];
        foreach ($this->filters as $filter) {
            $byId[$filter->id()] = $filter;
        }

        foreach ($format->enabledFilters() as $spec) {
            if (\in_array($spec['id'], $skip, true)) {
                continue;
            }
            if ($only !== null && !\in_array($spec['id'], $only, true)) {
                continue;
            }
            $filter = $byId[$spec['id']] ?? null;
            if ($filter === null || !\in_array($phase, $filter->phases(), true)) {
                continue;
            }

            $text = $filter->process($text, new TextFilterContext(
                $format->id,
                $phase,
                $spec['settings'],
                $tokenContext,
            ));
        }

        return $text;
    }

    /**
     * @return array<string, mixed>
     */
    private function restrictSettings(ResolvedTextFormat $format): array
    {
        foreach ($format->filters as $spec) {
            if ($spec['id'] === 'html_restrict') {
                return $spec['settings'];
            }
        }

        return [
            'allow_elements' => [
                'p' => [], 'br' => [], 'strong' => [], 'em' => [],
                'a' => ['href', 'title'], 'ul' => [], 'ol' => [], 'li' => [],
                'code' => [], 'pre' => [],
            ],
        ];
    }
}
