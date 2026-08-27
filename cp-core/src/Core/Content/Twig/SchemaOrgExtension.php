<?php

namespace App\Core\Content\Twig;

use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

final class SchemaOrgExtension extends AbstractExtension
{
    public function getFunctions(): array
    {
        return [
            new TwigFunction('cp_schema_org', [SchemaOrgRuntime::class, 'render']),
        ];
    }
}
