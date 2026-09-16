<?php

declare(strict_types=1);

namespace Modules\Showcase\Command;

/**
 * The words the demo seeder writes.
 *
 * Kept apart from the command so the command stays about orchestration. Every
 * body carries MARKER, which is how `--purge` tells a seeded entry from one an
 * operator wrote — matching on titles would eventually delete real content.
 */
final class DemoContent
{
    /**
     * Key stamped into each seeded entry's data bag; --purge matches on it.
     *
     * It lives in data rather than in the body because the body goes through
     * TextFormatProcessor::sanitizeForStorage(), which strips HTML comments —
     * a marker hidden there never reaches the database, and purge silently
     * matches nothing. The Field API leaves data keys it has no definition for
     * untouched, so this survives later edits.
     */
    public const MARKER_KEY = 'cp_showcase_demo';

    /**
     * @return list<string>
     */
    public static function categories(string $presetId): array
    {
        return match ($presetId) {
            'vehicle' => ['Sedan', 'SUV', 'Hatchback', 'Ticari'],
            'website' => ['E-ticaret', 'İçerik', 'SaaS', 'Blog'],
            'software' => ['Geliştirici Araçları', 'Eklenti', 'Mobil', 'Masaüstü'],
            'service' => ['Tasarım', 'Yazılım', 'Pazarlama', 'Danışmanlık'],
            'property' => ['Daire', 'Villa', 'Ofis', 'Arsa'],
            default => ['Genel'],
        };
    }

    /**
     * One entry blueprint. $index walks the sample list and wraps, so any count
     * produces varied rows rather than the same three repeated.
     *
     * @return array{
     *     title: string, summary: string, body: string, priceMode: string, price: string,
     *     externalUrl: string, demoUrl: string, phone: string, location: string,
     *     fields: array<string, mixed>
     * }
     */
    public static function item(string $presetId, int $index): array
    {
        $samples = self::samples($presetId);
        $sample = $samples[$index % \count($samples)];

        return [
            'title' => $sample['title'],
            'summary' => $sample['summary'],
            'body' => '<p>'.$sample['body'].'</p>',
            'priceMode' => $sample['priceMode'],
            'price' => $sample['price'],
            'externalUrl' => $sample['externalUrl'] ?? '',
            'demoUrl' => $sample['demoUrl'] ?? '',
            'phone' => '+90 5'.str_pad((string) (10000000 + ($index * 7919) % 89999999), 8, '0', \STR_PAD_LEFT),
            'location' => $sample['location'] ?? '',
            'fields' => $sample['fields'],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function samples(string $presetId): array
    {
        return match ($presetId) {
            'vehicle' => self::vehicles(),
            'website' => self::websites(),
            'software' => self::software(),
            'service' => self::services(),
            'property' => self::properties(),
            default => self::generic(),
        };
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function vehicles(): array
    {
        return [
            [
                'title' => 'Volkswagen Passat 1.6 TDI Comfortline',
                'summary' => 'Tek elden, bakımları yetkili serviste yapılmış, hatasız Passat.',
                'body' => 'Araç 2019 model olup düzenli servis bakımlarıyla kullanılmıştır. Lastikleri bu yıl değişti, muayenesi yenidir. Takas değerlendirilir.',
                'priceMode' => 'starting',
                'price' => '1250000',
                'location' => 'İstanbul, Kadıköy',
                'fields' => [
                    'brand' => 'Volkswagen', 'model' => 'Passat', 'model_year' => 2019,
                    'mileage_km' => 96000, 'fuel' => 'diesel', 'transmission' => 'automatic',
                    'body_condition' => 'used', 'color' => 'Antrasit Gri',
                ],
            ],
            [
                'title' => 'Renault Clio 1.0 TCe Joy',
                'summary' => 'Düşük kilometreli, garantisi devam eden ikinci el Clio.',
                'body' => 'Şehir içi kullanıma çok uygun, yakıt tüketimi düşük bir araç. Boya ve değişen yoktur, ekspertiz raporu mevcuttur.',
                'priceMode' => 'fixed',
                'price' => '785000',
                'location' => 'Ankara, Çankaya',
                'fields' => [
                    'brand' => 'Renault', 'model' => 'Clio', 'model_year' => 2021,
                    'mileage_km' => 38500, 'fuel' => 'petrol', 'transmission' => 'manual',
                    'body_condition' => 'used', 'color' => 'Beyaz',
                ],
            ],
            [
                'title' => 'Ford Transit 350L Panelvan',
                'summary' => 'Ticari kullanıma hazır, uzun şasi panelvan.',
                'body' => 'Filo aracıdır, tüm bakımları faturalıdır. Kasa içi raf sistemi ile birlikte verilir.',
                'priceMode' => 'starting',
                'price' => '1690000',
                'location' => 'İzmir, Bornova',
                'fields' => [
                    'brand' => 'Ford', 'model' => 'Transit', 'model_year' => 2020,
                    'mileage_km' => 184000, 'fuel' => 'diesel', 'transmission' => 'manual',
                    'body_condition' => 'used', 'color' => 'Beyaz',
                ],
            ],
            [
                'title' => 'Tesla Model 3 Long Range',
                'summary' => 'Çift motor, dört çeker; otopilot donanımı dahil.',
                'body' => 'Sıfır ayarında, garaj arabası. Ev şarj ünitesi ile birlikte devredilir.',
                'priceMode' => 'fixed',
                'price' => '2450000',
                'location' => 'İstanbul, Şişli',
                'fields' => [
                    'brand' => 'Tesla', 'model' => 'Model 3', 'model_year' => 2023,
                    'mileage_km' => 21000, 'fuel' => 'electric', 'transmission' => 'automatic',
                    'body_condition' => 'used', 'color' => 'Kırmızı',
                ],
            ],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function websites(): array
    {
        return [
            [
                'title' => 'Yemek Tarifleri Portalı — 180K Aylık Ziyaretçi',
                'summary' => 'Organik trafiği yüksek, reklam geliri istikrarlı içerik sitesi.',
                'body' => 'Altı yıldır yayında olan, 2.400 özgün tarif içeren bir portal. Trafiğin %78&#39;i organik aramadan geliyor. Analytics ve Search Console erişimi devir sırasında paylaşılır.',
                'priceMode' => 'starting',
                'price' => '420000',
                'externalUrl' => 'https://example.com',
                'demoUrl' => 'https://example.com/demo',
                'fields' => [
                    'domain' => 'lezzetdefteri.example', 'monthly_visitors' => 182000,
                    'monthly_revenue' => 18500, 'monetization' => 'ads', 'site_age_years' => 6,
                    'tech_stack' => 'WordPress, Cloudflare', 'analytics_url' => 'https://example.com/analytics',
                ],
            ],
            [
                'title' => 'Niş E-ticaret — Kahve Ekipmanları',
                'summary' => 'Kendi tedarik zinciri kurulu, kârlı butik e-ticaret.',
                'body' => 'Aylık ortalama 340 sipariş, sepet ortalaması 1.150 TL. Tedarikçi anlaşmaları devredilebilir.',
                'priceMode' => 'fixed',
                'price' => '890000',
                'externalUrl' => 'https://example.com',
                'fields' => [
                    'domain' => 'demlikdukkani.example', 'monthly_visitors' => 46000,
                    'monthly_revenue' => 96000, 'monetization' => 'ecommerce', 'site_age_years' => 3,
                    'tech_stack' => 'Shopify', 'analytics_url' => '',
                ],
            ],
            [
                'title' => 'SaaS — Fatura Takip Uygulaması',
                'summary' => 'Aylık abonelikli, 240 aktif müşterili küçük SaaS.',
                'body' => 'Kod tabanı Laravel, MRR 74.000 TL, iptal oranı aylık %2,1. Teknik devir desteği üç ay boyunca verilir.',
                'priceMode' => 'starting',
                'price' => '2100000',
                'externalUrl' => 'https://example.com',
                'demoUrl' => 'https://example.com/demo',
                'fields' => [
                    'domain' => 'faturakutusu.example', 'monthly_visitors' => 12800,
                    'monthly_revenue' => 74000, 'monetization' => 'subscription', 'site_age_years' => 4,
                    'tech_stack' => 'Laravel, PostgreSQL, Redis', 'analytics_url' => '',
                ],
            ],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function software(): array
    {
        return [
            [
                'title' => 'CPalius Yedekleme Eklentisi',
                'summary' => 'Zamanlanmış veritabanı ve dosya yedeği alan modül.',
                'body' => 'S3 uyumlu depolamaya şifreli yedek gönderir, geri yükleme sihirbazı içerir. Kaynak kodu satın alana devredilir.',
                'priceMode' => 'fixed',
                'price' => '4500',
                'externalUrl' => 'https://example.com',
                'demoUrl' => 'https://example.com/demo',
                'fields' => [
                    'version' => '2.3.1', 'license' => 'proprietary', 'platform' => 'web',
                    'tech_language' => 'PHP', 'repository_url' => 'https://example.com/repo',
                    'docs_url' => 'https://example.com/docs', 'released_at' => '2026-05-14',
                ],
            ],
            [
                'title' => 'Log Görselleştirici (Açık Kaynak)',
                'summary' => 'Sunucu loglarını gerçek zamanlı grafiğe çeviren küçük araç.',
                'body' => 'Tek binary olarak dağıtılır, harici bağımlılığı yoktur. MIT lisanslıdır; kurumsal destek paketi ayrıca sunulur.',
                'priceMode' => 'free',
                'price' => '',
                'externalUrl' => 'https://example.com',
                'fields' => [
                    'version' => '1.8.0', 'license' => 'mit', 'platform' => 'linux',
                    'tech_language' => 'Go', 'repository_url' => 'https://example.com/repo',
                    'docs_url' => 'https://example.com/docs', 'released_at' => '2026-08-02',
                ],
            ],
            [
                'title' => 'Mobil Stok Sayım Uygulaması',
                'summary' => 'Barkod okuyuculu, çevrimdışı çalışabilen sayım uygulaması.',
                'body' => 'Android ve iOS için tek kod tabanı. Depo sayımını çevrimdışı tamamlayıp bağlantı gelince senkronize eder.',
                'priceMode' => 'subscription',
                'price' => '1200',
                'externalUrl' => 'https://example.com',
                'fields' => [
                    'version' => '4.0.2', 'license' => 'freemium', 'platform' => 'android',
                    'tech_language' => 'Dart', 'repository_url' => '',
                    'docs_url' => 'https://example.com/docs', 'released_at' => '2026-07-21',
                ],
            ],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function services(): array
    {
        return [
            [
                'title' => 'Kurumsal Web Sitesi Tasarımı',
                'summary' => 'Marka kimliğine uygun, erişilebilir kurumsal site tasarımı.',
                'body' => 'Keşif toplantısı, tel kafes, arayüz tasarımı ve geliştirici teslimi dahildir. Süreç ortalama üç hafta sürer.',
                'priceMode' => 'starting',
                'price' => '65000',
                'location' => 'Uzaktan',
                'fields' => [
                    'delivery_days' => 21, 'revision_count' => 3,
                    'experience_years' => 9, 'remote_available' => true,
                ],
            ],
            [
                'title' => 'Teknik SEO Denetimi',
                'summary' => 'Tarama, indeksleme ve hız sorunlarının çıkarıldığı detaylı rapor.',
                'body' => 'Site genelinde tarama yapılır, öncelik sırasına konmuş bir aksiyon listesi teslim edilir. Uygulama desteği opsiyoneldir.',
                'priceMode' => 'fixed',
                'price' => '28000',
                'location' => 'Uzaktan',
                'fields' => [
                    'delivery_days' => 10, 'revision_count' => 1,
                    'experience_years' => 7, 'remote_available' => true,
                ],
            ],
            [
                'title' => 'Sunucu Kurulumu ve Sıkılaştırma',
                'summary' => 'Üretim ortamı kurulumu, yedekleme ve güvenlik sıkılaştırması.',
                'body' => 'Nginx, PHP-FPM, MariaDB kurulumu; otomatik yedekleme, güvenlik duvarı ve izleme yapılandırması dahildir.',
                'priceMode' => 'starting',
                'price' => '19000',
                'location' => 'Uzaktan',
                'fields' => [
                    'delivery_days' => 5, 'revision_count' => 2,
                    'experience_years' => 12, 'remote_available' => true,
                ],
            ],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function properties(): array
    {
        return [
            [
                'title' => '3+1 Bahçe Katı Daire',
                'summary' => 'Site içinde, otoparklı, güneydoğu cepheli geniş daire.',
                'body' => 'Site içerisinde kapalı otopark ve çocuk oyun alanı bulunmaktadır. Aidat aylık 2.400 TL&#39;dir.',
                'priceMode' => 'starting',
                'price' => '4850000',
                'location' => 'İzmir, Karşıyaka',
                'fields' => [
                    'rooms' => '3+1', 'area_m2' => 145, 'floor_no' => 1,
                    'building_age' => 6, 'heating' => 'natural_gas',
                ],
            ],
            [
                'title' => 'Merkezde 2+1 Kiralık Ofis',
                'summary' => 'Metroya yürüme mesafesinde, asansörlü binada ofis katı.',
                'body' => 'Bina girişinde güvenlik bulunur. Ofis bölmeli olarak teslim edilir, mobilyalar dahil değildir.',
                'priceMode' => 'fixed',
                'price' => '52000',
                'location' => 'Ankara, Kızılay',
                'fields' => [
                    'rooms' => '2+1', 'area_m2' => 96, 'floor_no' => 4,
                    'building_age' => 14, 'heating' => 'central',
                ],
            ],
            [
                'title' => 'Deniz Manzaralı Müstakil Villa',
                'summary' => 'Havuzlu, üç katlı, tam eşyalı yazlık villa.',
                'body' => 'Parsel 620 m², kapalı alan 240 m². Havuz ve peyzaj bakımı dahil yönetim hizmeti mevcuttur.',
                'priceMode' => 'negotiable',
                'price' => '',
                'location' => 'Muğla, Bodrum',
                'fields' => [
                    'rooms' => '5+', 'area_m2' => 240, 'floor_no' => 0,
                    'building_age' => 3, 'heating' => 'electric',
                ],
            ],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function generic(): array
    {
        return [[
            'title' => 'Örnek Vitrin Kaydı',
            'summary' => 'Vitrinin nasıl göründüğünü denemek için oluşturulmuş örnek kayıt.',
            'body' => 'Bu kayıt demo verisidir; silebilir veya düzenleyebilirsiniz.',
            'priceMode' => 'none',
            'price' => '',
            'fields' => [],
        ]];
    }
}
