<?php

declare(strict_types=1);

namespace Modules\Pages\Field;

/**
 * Allowlisted ACF field kinds stored in Node::data['custom_fields'].
 */
final class PageFieldType
{
    public const TEXT = 'text';
    public const TEXTAREA = 'textarea';
    public const WYSIWYG = 'wysiwyg';
    public const IMAGE = 'image';
    public const GALLERY = 'gallery';
    public const URL = 'url';
    public const EMAIL = 'email';
    public const NUMBER = 'number';
    public const CHECKBOX = 'checkbox';
    public const SELECT = 'select';
    public const DATE = 'date';

    /**
     * @return array<string, string> label translation key => value
     */
    public static function choices(): array
    {
        return [
            'pages.field.type.text' => self::TEXT,
            'pages.field.type.textarea' => self::TEXTAREA,
            'pages.field.type.wysiwyg' => self::WYSIWYG,
            'pages.field.type.image' => self::IMAGE,
            'pages.field.type.gallery' => self::GALLERY,
            'pages.field.type.url' => self::URL,
            'pages.field.type.email' => self::EMAIL,
            'pages.field.type.number' => self::NUMBER,
            'pages.field.type.checkbox' => self::CHECKBOX,
            'pages.field.type.select' => self::SELECT,
            'pages.field.type.date' => self::DATE,
        ];
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_values(self::choices());
    }

    public static function isValid(string $type): bool
    {
        return \in_array($type, self::values(), true);
    }

    public static function isRichText(string $type): bool
    {
        return $type === self::WYSIWYG;
    }
}
