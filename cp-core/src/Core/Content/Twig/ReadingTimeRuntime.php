<?php

declare(strict_types=1);

namespace App\Core\Content\Twig;

use Twig\Extension\RuntimeExtensionInterface;

/**
 * {{ html|reading_time }} filtresinin veri kaynağı. SchemaOrgRuntime ile
 * aynı desen: AbstractExtension sadece kaydı yapar, gerçek hesaplama
 * burada (RuntimeExtensionInterface, lazy-loaded) yaşar.
 *
 * str_word_count() BİLİNÇLİ OLARAK KULLANILMAZ: bu fonksiyon yerelleşmiş
 * (locale-aware) değildir ve Türkçe'ye özgü karakterleri (ı, ş, ğ, ü, ö, ç)
 * kelime sınırı sanıp kelimeleri yanlış böler — bu da okuma süresini
 * olduğundan yüksek gösterir. Bunun yerine Unicode destekli (/u
 * modifier'lı) bir boşluk-ayırma regex'i kullanılır: her bir Unicode
 * harf/rakam dizisini, içindeki Türkçe karakterlerden bağımsız olarak
 * tek bir kelime sayar.
 */
final class ReadingTimeRuntime implements RuntimeExtensionInterface
{
    private const WORDS_PER_MINUTE = 200;

    public function calculate(?string $html): int
    {
        if ($html === null || trim($html) === '') {
            return 0;
        }

        $plainText = strip_tags($html);

        $words = preg_split('/\s+/u', trim($plainText), -1, PREG_SPLIT_NO_EMPTY);
        $wordCount = is_array($words) ? count($words) : 0;

        if ($wordCount === 0) {
            return 0;
        }

        return (int) ceil($wordCount / self::WORDS_PER_MINUTE);
    }
}
