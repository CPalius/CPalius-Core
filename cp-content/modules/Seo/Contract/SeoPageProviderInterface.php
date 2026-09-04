<?php

declare(strict_types=1);

namespace Modules\Seo\Contract;

use Modules\Seo\Document\SeoDocument;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;
use Symfony\Component\HttpFoundation\Request;

#[AutoconfigureTag('cpalius.seo.page_provider')]
interface SeoPageProviderInterface
{
    public function priority(): int;

    public function supports(Request $request): bool;

    public function document(Request $request): ?SeoDocument;
}
