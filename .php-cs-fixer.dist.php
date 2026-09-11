<?php

declare(strict_types=1);

/*
 * CPalius CMF — kod stili yapılandırması
 *
 * NEDEN ".dist" SÜRÜMÜ?
 *   phpunit.xml.dist ve phpstan.neon.dist ile aynı gerekçe: depoya giren
 *   taban yapılandırma budur; bir geliştirici yerel olarak ezmek isterse
 *   yanına ".php-cs-fixer.php" koyar.
 *
 * KURAL SEÇİMİ
 *   Taban @Symfony, çünkü CPalius bir Symfony uygulaması ve mevcut kod
 *   zaten büyük ölçüde o stile yazılmış. Üzerine eklenenler CPalius'un
 *   kendi yazılı kurallarını MAKİNEYLE DENETLENİR hâle getiriyor:
 *   strict_types her dosyada zorunlu (manifesto kuralı), final sınıf
 *   konvansiyonu, sıralı use ifadeleri.
 *
 *   Bilinçli olarak KAPALI bırakılanlar aşağıda tek tek gerekçelendirildi.
 *   Bir kuralı kapatmanın gerekçesi yazılmıyorsa, o kural açık olmalıdır.
 */

$finder = (new PhpCsFixer\Finder())
    ->in([
        __DIR__.'/cp-core/src',
        __DIR__.'/cp-core/tests',
        __DIR__.'/cp-content/modules',
    ])
    ->exclude([
        'var',
        // Modül iskeleti şablonları geçerli PHP değil, yer tutucu içerir.
        'Skeleton',
        'skeleton',
    ])
    ->notPath('#/Resources/skeleton/#')
    ->append([
        __DIR__.'/public/index.php',
        __DIR__.'/importmap.php',
    ]);

return (new PhpCsFixer\Config())
    ->setFinder($finder)
    ->setRiskyAllowed(true)
    ->setCacheFile(__DIR__.'/cp-core/var/.php-cs-fixer.cache')
    ->setRules([
        '@Symfony' => true,

        // Manifesto kuralı: her PHP dosyası strict_types ile başlar.
        'declare_strict_types' => true,
        'strict_param' => true,

        // use ifadeleri alfabetik ve tek satırlık — diff gürültüsünü azaltır,
        // birleştirme çakışmalarını neredeyse sıfırlar.
        'ordered_imports' => [
            'sort_algorithm' => 'alpha',
            'imports_order' => ['class', 'function', 'const'],
        ],
        'no_unused_imports' => true,
        'global_namespace_import' => [
            'import_classes' => false,
            'import_constants' => false,
            'import_functions' => false,
        ],

        // Modern PHP 8.4 sözdizimi.
        'modernize_strpos' => true,
        'get_class_to_class_keyword' => true,
        'nullable_type_declaration_for_default_null_value' => true,

        // Test okunabilirliği.
        'php_unit_method_casing' => ['case' => 'camel_case'],
        'php_unit_test_annotation' => ['style' => 'prefix'],

        /*
         * BİLİNÇLİ OLARAK KAPALI
         *
         * yoda_style: @Symfony bunu açar. CPalius'un mevcut kodu baştan sona
         *   düz karşılaştırma ($x === null) kullanıyor; 500+ dosyayı Yoda'ya
         *   çevirmek devasa bir diff üretir, hiçbir bug yakalamaz ve
         *   incelenebilirliği düşürür.
         */
        'yoda_style' => false,

        /*
         * phpdoc_summary: her PHPDoc'un noktayla bitmesini ister. Kod
         *   tabanındaki yorumlar tam cümlelerle yazılmış ama çok sayıda kısa
         *   @var/@param satırı var; bu kural gerçek bir hata sınıfı
         *   yakalamadan yüzlerce dosyayı değiştirirdi.
         */
        'phpdoc_summary' => false,

        /*
         * native_function_invocation: mikro-optimizasyon amaçlı "\" öneki
         *   ekler. Ölçülebilir bir kazanç sağlamadığı, buna karşılık her
         *   dosyaya dokunduğu için kapalı.
         */
        'native_function_invocation' => false,
    ]);
