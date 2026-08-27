<?php

namespace App\Core\Content\Twig;

use App\Core\Content\SchemaOrgBuilder;
use App\Entity\Node;
use Twig\Extension\RuntimeExtensionInterface;

final class SchemaOrgRuntime implements RuntimeExtensionInterface
{
    public function __construct(
        private readonly SchemaOrgBuilder $schemaOrgBuilder,
    ) {
    }

    public function render(Node $node): string
    {
        return $this->schemaOrgBuilder->buildJson($node);
    }
}
