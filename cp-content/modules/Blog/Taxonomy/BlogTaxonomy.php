<?php

declare(strict_types=1);

namespace Modules\Blog\Taxonomy;

use App\Core\Taxonomy\DefaultVocabularies;

/**
 * @deprecated use DefaultVocabularies — kept as Blog-facing alias
 */
final class BlogTaxonomy
{
    public const CATEGORY = DefaultVocabularies::BLOG_CATEGORY;
    public const TAG = DefaultVocabularies::BLOG_TAG;

    private function __construct()
    {
    }
}
