<?php

namespace App\Core\Content;

/**
 * Node::data (JSON) içindeki hangi alanların NodeFieldIndex tablosuna
 * yansıtılacağını ve hangi value* kolonuna (tipe) yazılacağını belirleyen
 * merkezi konfigürasyon. Şimdilik çekirdeğe gömülü statik bir harita —
 * ileride modüllerin kendi alanlarını buraya kaydedebilmesi için (Compiler
 * Pass / tagged service ile) genişletilebilir, ama bugünkü ihtiyaç için
 * fazladan soyutlama eklemiyoruz.
 */
final class QueryableFieldsRegistry
{
    public const TYPE_STRING = 'string';
    public const TYPE_INT = 'int';
    public const TYPE_DECIMAL = 'decimal';
    public const TYPE_DATETIME = 'datetime';

    /**
     * @var array<string, array<string, self::TYPE_*>>
     *   content type => [fieldName => valueType]
     */
    private const FIELDS = [
        'post' => [
            // Blog modülü: PostFormModel::$isFeatured (bool) buradan
            // PostAdminController::mapDtoToNode() içinde 0/1'e çevrilerek
            // Node::data['is_featured']'a yazılır; NodeIndexListener bunu
            // postPersist/postUpdate sonrası NodeFieldIndex.value_int'e
            // otomatik düzleştirir (Manifesto Law 6.3).
            'is_featured' => self::TYPE_INT,
            // Modules\Blog\PostSubType ('makale'|'proje'|'yazilim'|'not').
            // Ön yüzde/admin listesinde türe göre filtreleme yapılabilmesi
            // için düzleştirilir — JSON içi string karşılaştırma yerine
            // NodeRepository::findByIndexedField() ile indekslenmiş bir
            // SQL WHERE koşulu kullanılabilir hale gelir.
            'post_sub_type' => self::TYPE_STRING,
        ],
        'product' => [
            'price' => self::TYPE_DECIMAL,
            'is_featured' => self::TYPE_INT,
        ],
        'event' => [
            'event_date' => self::TYPE_DATETIME,
        ],
    ];

    /**
     * Verilen içerik tipi için indekslenecek [fieldName => valueType] haritasını döner.
     *
     * @return array<string, self::TYPE_*>
     */
    public function getFieldsForType(string $nodeType): array
    {
        return self::FIELDS[$nodeType] ?? [];
    }
}
