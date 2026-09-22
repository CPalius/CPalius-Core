<?php

declare(strict_types=1);

namespace Modules\DnsTools\Catalog;

/**
 * Catalogue row after Studio overrides (enabled, featured, locale copy) are applied.
 */
final class PresentedTool
{
    public function __construct(
        public readonly ToolDefinition $definition,
        public readonly string $slug,
        public readonly string $category,
        public readonly string $icon,
        public readonly string $input,
        public readonly bool $featured,
        public readonly bool $isNew,
        public readonly bool $networkProbe,
        public readonly bool $enabled,
        public readonly string $title,
        public readonly string $subtitle,
        public readonly string $description,
        public readonly string $keywords,
    ) {
    }

    public static function fromDefinition(ToolDefinition $tool, bool $enabled, bool $featured, bool $isNew, string $title, string $subtitle, string $description, string $keywords): self
    {
        return new self(
            definition: $tool,
            slug: $tool->slug,
            category: $tool->category,
            icon: $tool->icon,
            input: $tool->input,
            featured: $featured,
            isNew: $isNew,
            networkProbe: $tool->networkProbe,
            enabled: $enabled,
            title: $title,
            subtitle: $subtitle,
            description: $description,
            keywords: $keywords,
        );
    }

    public function placeholderKey(): string
    {
        return $this->definition->placeholderKey();
    }
}
