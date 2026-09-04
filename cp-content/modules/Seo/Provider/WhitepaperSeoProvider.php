<?php

declare(strict_types=1);

namespace Modules\Seo\Provider;

use Modules\Seo\Contract\SeoPageProviderInterface;
use Modules\Seo\Document\SeoDocument;
use Modules\Seo\Engine\SeoUrlBuilder;
use Symfony\Component\HttpFoundation\Request;

final class WhitepaperSeoProvider implements SeoPageProviderInterface
{
    public function __construct(
        private readonly SeoUrlBuilder $urls,
    ) {
    }

    public function priority(): int
    {
        return 50;
    }

    public function supports(Request $request): bool
    {
        return $request->attributes->get('_route') === 'theme_whitepaper';
    }

    public function document(Request $request): ?SeoDocument
    {
        $locale = (string) $request->getLocale();
        $url = $this->urls->absolute('theme_whitepaper', ['_locale' => $locale], $locale);

        return new SeoDocument(
            headline: 'Whitepaper',
            description: '',
            canonicalPath: $url,
            ogType: 'article',
            contentKind: 'page',
            schemaType: 'TechArticle',
            breadcrumbs: [
                ['name' => 'Home', 'url' => $this->urls->absolute('theme_cpalius_website_home', ['_locale' => $locale], $locale)],
            ],
            locale: $locale,
        );
    }
}
