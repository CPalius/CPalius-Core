<?php

declare(strict_types=1);

namespace Modules\DnsTools\Catalog;

final class ToolDefinition
{
    public function __construct(
        public readonly string $slug,
        public readonly string $category,
        public readonly string $icon,
        public readonly string $input,
        public readonly bool $featured = false,
        public readonly bool $isNew = false,
        public readonly bool $networkProbe = false,
        public readonly string $schemaType = 'WebApplication',
    ) {
    }

    public function titleKey(): string
    {
        return 'dnstools.tool.'.$this->slug.'.title';
    }

    public function subtitleKey(): string
    {
        return 'dnstools.tool.'.$this->slug.'.subtitle';
    }

    public function descriptionKey(): string
    {
        return 'dnstools.tool.'.$this->slug.'.description';
    }

    public function keywordsKey(): string
    {
        return 'dnstools.tool.'.$this->slug.'.keywords';
    }

    public function placeholderKey(): string
    {
        return 'dnstools.input.'.$this->input;
    }
}
