<?php

declare(strict_types=1);

namespace Modules\Blog;

/**
 * Secondary post kind in Node::data['post_sub_type'] — not Node::type (Law 3.1).
 * Plain string constants (four kinds); not a PHP enum to avoid extra mapping layers.
 */
final class PostSubType
{
    public const ARTICLE = 'makale';
    public const PROJECT = 'proje';
    public const SOFTWARE = 'yazilim';
    public const NOTE = 'not';

    /**
     * Choice map for forms/Assert\Choice (label => value).
     *
     * @return array<string, string>
     */
    public static function choices(): array
    {
        return [
            'blog.posts.sub_type.article' => self::ARTICLE,
            'blog.posts.sub_type.project' => self::PROJECT,
            'blog.posts.sub_type.software' => self::SOFTWARE,
            'blog.posts.sub_type.note' => self::NOTE,
        ];
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_values(self::choices());
    }

    public static function isValid(string $subType): bool
    {
        return \in_array($subType, self::values(), true);
    }

    /**
     * Unknown values fall back to article (same as Twig template dispatch).
     */
    public static function label(string $subType): string
    {
        return match ($subType) {
            self::PROJECT => 'blog.posts.sub_type.project',
            self::SOFTWARE => 'blog.posts.sub_type.software',
            self::NOTE => 'blog.posts.sub_type.note',
            default => 'blog.posts.sub_type.article',
        };
    }
}
