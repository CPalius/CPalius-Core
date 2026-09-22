<?php

declare(strict_types=1);

namespace Modules\Ai\Service;

final readonly class AiTranslationResult
{
    public function __construct(
        public string $title,
        public string $body,
        public string $description = '',
        public string $excerpt = '',
    ) {
    }
}
