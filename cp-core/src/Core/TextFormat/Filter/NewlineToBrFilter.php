<?php

declare(strict_types=1);

namespace App\Core\TextFormat\Filter;

use App\Core\TextFormat\TextFilterContext;
use App\Core\TextFormat\TextFilterInterface;

/**
 * Converts newlines to `<br>` for non-WYSIWYG formats (restricted). HTML
 * formats already carry `<p>`/`<br>` from the editor, so they leave this off.
 */
final class NewlineToBrFilter implements TextFilterInterface
{
    public function id(): string
    {
        return 'nl2br';
    }

    public function phases(): array
    {
        return [TextFilterContext::PHASE_OUTPUT];
    }

    public function process(string $text, TextFilterContext $context): string
    {
        return nl2br($text, false);
    }
}
