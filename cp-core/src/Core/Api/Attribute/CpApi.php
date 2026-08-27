<?php

declare(strict_types=1);

namespace App\Core\Api\Attribute;

/**
 * Bir servis metodunu ApiGatewayController üzerinden dış dünyaya açan
 * sözleşme. CpAdminMenu/CpHook ile aynı felsefe: modüller Symfony'nin
 * routing/security karmaşasına hiç dokunmadan, sadece bu attribute'u
 * eklediği bir metotla "/api/*" altında bir uç noktaya sahip olur.
 *
 * TARGET_METHOD, IS_REPEATABLE DEĞİL: her metot TEK bir API uç noktasını
 * temsil eder (path + methods birebir bir HTTP sözleşmesidir; aynı metodun
 * iki farklı path'e sahip olması, iki farklı uç nokta anlamına gelir ve
 * bu, ayrı ayrı metotlarla ifade edilmelidir — CpAdminMenu ile aynı
 * TARGET_METHOD/tekil kısıtı, CpHook'un REPEATABLE'ından BİLİNÇLİ olarak
 * farklıdır).
 *
 * Kullanım (modül tarafında):
 *   final class BlogApiEndpoints
 *   {
 *       #[CpApi(path: '/blog/posts', methods: ['GET'], public: true)]
 *       public function listPosts(Request $request): JsonResponse { ... }
 *   }
 * $path DAİMA "/api" önekiyle BİRLEŞTİRİLİR (bkz. ApiRegistrationPass) —
 * modül geliştiricisi kendi $path'inde "/api" önekini TEKRARLAMAMALIDIR.
 */
#[\Attribute(\Attribute::TARGET_METHOD)]
final class CpApi
{
    /**
     * @param string $path "/api" önekinden SONRA gelen yol (ör. "/blog/posts").
     *   Symfony route requirements formatını destekler (ör. "/blog/posts/{id}").
     * @param list<string> $methods İzin verilen HTTP metotları (ör. ['GET', 'POST']).
     * @param bool $public false ise (varsayılan) ApiGatewayController isteği
     *   çalıştırmadan ÖNCE "X-CP-API-KEY" header'ını doğrular; geçersiz/eksik
     *   anahtarda 401 döner. true ise hiçbir kimlik doğrulama yapılmaz —
     *   sadece gerçekten herkese açık olması gereken uçlar için kullanılmalıdır.
     */
    public function __construct(
        public readonly string $path,
        public readonly array $methods = ['GET'],
        public readonly bool $public = false,
    ) {
    }
}
