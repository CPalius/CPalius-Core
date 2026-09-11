<?php

declare(strict_types=1);

namespace Modules\Pages;

/**
 * Front layout kind stored in Node::data['template'] — not Node::type (Law 3.1).
 */
final class PageTemplate
{
    public const DEFAULT = 'default';
    public const FULLWIDTH = 'fullwidth';
    public const LANDING = 'landing';

    /**
     * @return array<string, string>
     */
    public static function choices(): array
    {
        return [
            'pages.template.default' => self::DEFAULT,
            'pages.template.fullwidth' => self::FULLWIDTH,
            'pages.template.landing' => self::LANDING,
        ];
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_values(self::choices());
    }

    public static function isValid(string $template): bool
    {
        return \in_array($template, self::values(), true);
    }
}
