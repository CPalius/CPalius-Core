<?php

declare(strict_types=1);

namespace Modules\Pages\Field;

use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Built-in ACF schemas for About / Contact style pages.
 */
final class PageFieldPresets
{
    /**
     * @return list<array{identifier: string, title: string, fields: list<array<string, mixed>>}>
     */
    public static function all(TranslatorInterface $translator, ?string $locale = null): array
    {
        $t = static fn (string $key): string => $translator->trans($key, [], null, $locale);

        return [
            [
                'identifier' => 'fg-about',
                'title' => $t('pages.preset.about.title'),
                'fields' => [
                    self::field('subtitle', PageFieldType::TEXT, $t('pages.preset.about.subtitle')),
                    self::field('hero_image', PageFieldType::IMAGE, $t('pages.preset.about.hero_image')),
                    self::field('mission', PageFieldType::WYSIWYG, $t('pages.preset.about.mission')),
                    self::field('vision', PageFieldType::WYSIWYG, $t('pages.preset.about.vision')),
                    self::field('values', PageFieldType::TEXTAREA, $t('pages.preset.about.values')),
                ],
            ],
            [
                'identifier' => 'fg-contact',
                'title' => $t('pages.preset.contact.title'),
                'fields' => [
                    self::field('email', PageFieldType::EMAIL, $t('pages.preset.contact.email'), true),
                    self::field('phone', PageFieldType::TEXT, $t('pages.preset.contact.phone')),
                    self::field('address', PageFieldType::TEXTAREA, $t('pages.preset.contact.address')),
                    self::field('working_hours', PageFieldType::TEXT, $t('pages.preset.contact.working_hours')),
                    self::field('map_url', PageFieldType::URL, $t('pages.preset.contact.map_url')),
                ],
            ],
            [
                'identifier' => 'fg-landing',
                'title' => $t('pages.preset.landing.title'),
                'fields' => [
                    self::field('eyebrow', PageFieldType::TEXT, $t('pages.preset.landing.eyebrow')),
                    self::field('lead', PageFieldType::TEXTAREA, $t('pages.preset.landing.lead')),
                    self::field('cta_label', PageFieldType::TEXT, $t('pages.preset.landing.cta_label')),
                    self::field('cta_url', PageFieldType::URL, $t('pages.preset.landing.cta_url')),
                    self::field('gallery', PageFieldType::GALLERY, $t('pages.preset.landing.gallery')),
                ],
            ],
        ];
    }

    /**
     * @return array{id: string, key: string, type: string, label: string, required: bool, choices: list<string>, value: null}
     */
    private static function field(string $key, string $type, string $label, bool $required = false, array $choices = []): array
    {
        return [
            'id' => $key,
            'key' => $key,
            'type' => $type,
            'label' => $label,
            'required' => $required,
            'choices' => $choices,
            'value' => null,
        ];
    }
}
