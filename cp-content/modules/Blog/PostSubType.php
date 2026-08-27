<?php

declare(strict_types=1);

namespace Modules\Blog;

/**
 * Blog modülünün "post" tipi Node'ları için içerik alt-türü sabitleri.
 *
 * Node::type ('post') ile KARIŞTIRILMAMALIDIR: bu, Manifesto Law 3.1'in
 * "yeni bir içerik tipi için migration gerekmez" ilkesinin bir adım
 * ilerisi — aynı Node::type = 'post' çatısı altında, Node::data JSON'u
 * içinde saklanan İKİNCİL bir sınıflandırmadır (bkz. Node::data['post_sub_type']).
 *
 * Bilinçli olarak bir PHP native enum DEĞİLDİR: Node::data zaten JSON'a
 * yazılırken string'e döner (enum backed value'sundan farksız), form
 * katmanında (PostFormModel) da düz string olarak taşınır — enum burada
 * ekstra tip dönüşümü katmanından başka bir şey kazandırmaz.
 *
 * Slawman'daki PostType enum + PostTypeConfig (318 satır, 9 tür) BİLİNÇLİ
 * OLARAK kopyalanmaz: kullanıcının talep ettiği kapsam yalnızca 4 türle
 * sınırlıdır (makale, proje, yazılım, not) — YAGNI, gereksiz soyutlama
 * eklenmez.
 */
final class PostSubType
{
    public const ARTICLE = 'makale';
    public const PROJECT = 'proje';
    public const SOFTWARE = 'yazilim';
    public const NOTE = 'not';

    /**
     * Symfony Form ChoiceType ve Assert\Choice callback'i için kullanılan
     * seçenek haritası (etiket => değer).
     *
     * @return array<string, string>
     */
    public static function choices(): array
    {
        return [
            'Makale' => self::ARTICLE,
            'Proje' => self::PROJECT,
            'Yazılım / Ürün' => self::SOFTWARE,
            'Not' => self::NOTE,
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
     * Bilinmeyen/tanımsız bir değer için her zaman 'makale'ye düşer
     * (fail-safe) — Twig şablon dispatch'indeki fallback deseniyle tutarlı.
     */
    public static function label(string $subType): string
    {
        return match ($subType) {
            self::PROJECT => 'Proje',
            self::SOFTWARE => 'Yazılım / Ürün',
            self::NOTE => 'Not',
            default => 'Makale',
        };
    }
}
