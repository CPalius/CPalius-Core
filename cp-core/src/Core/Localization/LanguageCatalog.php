<?php

declare(strict_types=1);

namespace App\Core\Localization;

/**
 * Languages offered in the "add language" picker, so an operator chooses
 * "Deutsch" instead of typing a code, an English name and a native name by hand.
 * Not a whitelist: the form still accepts any [a-z]{2,5} code.
 */
final class LanguageCatalog
{
    /** @var array<string, array{0: string, 1: string}> code => [English name, native name] */
    private const LANGUAGES = [
        'ar' => ['Arabic', 'العربية'],
        'az' => ['Azerbaijani', 'Azərbaycanca'],
        'bg' => ['Bulgarian', 'Български'],
        'bn' => ['Bengali', 'বাংলা'],
        'bs' => ['Bosnian', 'Bosanski'],
        'ca' => ['Catalan', 'Català'],
        'cs' => ['Czech', 'Čeština'],
        'da' => ['Danish', 'Dansk'],
        'de' => ['German', 'Deutsch'],
        'el' => ['Greek', 'Ελληνικά'],
        'en' => ['English', 'English'],
        'es' => ['Spanish', 'Español'],
        'et' => ['Estonian', 'Eesti'],
        'fa' => ['Persian', 'فارسی'],
        'fi' => ['Finnish', 'Suomi'],
        'fr' => ['French', 'Français'],
        'he' => ['Hebrew', 'עברית'],
        'hi' => ['Hindi', 'हिन्दी'],
        'hr' => ['Croatian', 'Hrvatski'],
        'hu' => ['Hungarian', 'Magyar'],
        'hy' => ['Armenian', 'Հայերեն'],
        'id' => ['Indonesian', 'Bahasa Indonesia'],
        'it' => ['Italian', 'Italiano'],
        'ja' => ['Japanese', '日本語'],
        'ka' => ['Georgian', 'ქართული'],
        'kk' => ['Kazakh', 'Қазақша'],
        'ko' => ['Korean', '한국어'],
        'lt' => ['Lithuanian', 'Lietuvių'],
        'lv' => ['Latvian', 'Latviešu'],
        'mk' => ['Macedonian', 'Македонски'],
        'ms' => ['Malay', 'Bahasa Melayu'],
        'nl' => ['Dutch', 'Nederlands'],
        'no' => ['Norwegian', 'Norsk'],
        'pl' => ['Polish', 'Polski'],
        'pt' => ['Portuguese', 'Português'],
        'ro' => ['Romanian', 'Română'],
        'ru' => ['Russian', 'Русский'],
        'sk' => ['Slovak', 'Slovenčina'],
        'sl' => ['Slovenian', 'Slovenščina'],
        'sq' => ['Albanian', 'Shqip'],
        'sr' => ['Serbian', 'Српски'],
        'sv' => ['Swedish', 'Svenska'],
        'th' => ['Thai', 'ไทย'],
        'tr' => ['Turkish', 'Türkçe'],
        'uk' => ['Ukrainian', 'Українська'],
        'ur' => ['Urdu', 'اردو'],
        'uz' => ['Uzbek', 'Oʻzbekcha'],
        'vi' => ['Vietnamese', 'Tiếng Việt'],
        'zh' => ['Chinese', '中文'],
    ];

    /**
     * @return array<string, array{name: string, native: string}>
     */
    public static function all(): array
    {
        $out = [];
        foreach (self::LANGUAGES as $code => [$name, $native]) {
            $out[$code] = ['name' => $name, 'native' => $native];
        }

        return $out;
    }

    /**
     * @return array{name: string, native: string}|null
     */
    public static function find(string $code): ?array
    {
        return self::all()[strtolower($code)] ?? null;
    }
}
