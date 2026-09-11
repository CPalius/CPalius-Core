<?php

declare(strict_types=1);

namespace App\Core\Taxonomy;

/**
 * Default vocabulary machine names used by the Blog content type.
 * Kept in core so CategoryRepository/TagRepository stay module-free.
 */
final class DefaultVocabularies
{
    public const BLOG_CATEGORY = 'blog_category';
    public const BLOG_TAG = 'blog_tag';

    private function __construct()
    {
    }
}
