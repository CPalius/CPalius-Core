<?php

declare(strict_types=1);

namespace App\Core\TextFormat;

/**
 * One step in a named text format's pipeline. Tagged `cp.text_filter`
 * (autoconfigured via _instanceof). TokenReplacer-style: the processor
 * dispatches to the ONE filter whose id() matches — no hook fan-out.
 */
interface TextFilterInterface
{
    public function id(): string;

    /**
     * @return list<string> TextFilterContext::PHASE_* values this filter runs in
     */
    public function phases(): array;

    public function process(string $text, TextFilterContext $context): string;
}
