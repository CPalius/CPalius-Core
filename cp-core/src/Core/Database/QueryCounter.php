<?php

declare(strict_types=1);

namespace App\Core\Database;

/**
 * Manifesto Law 6.1'in durum tutucusu: tek bir HTTP isteği (PHP process
 * ömrü) boyunca, hangi tabloya kaç kez SELECT atıldığını sayar.
 * QueryCounterMiddleware her SELECT'te increment() çağırır; limit
 * aşılırsa MaxQueriesExceededException fırlatılır.
 *
 * Bilinçli olarak tablo adına göre sayar (SQL metnine göre DEĞİL): aynı
 * tabloya "WHERE id = 1" ve "WHERE id = 2" gibi sadece parametresi
 * farklı N+1 sorguları da yakalamak istiyoruz; ham SQL string'i bunları
 * ayrı sorgular gibi göstermez zaten (placeholder kullanılıyorsa), ama
 * tablo bazlı sayım hem daha basit hem de placeholder kullanmayan (inline
 * değerli) sorguları da güvenilir şekilde yakalar.
 *
 * UPDATE/INSERT/DELETE sayılmaz — N+1 lazy-loading bir okuma sorunudur;
 * meşru toplu yazmalar (ör. ayar formu flush) yanlış pozitif üretmemeli.
 */
final class QueryCounter
{
    /** @var array<string, int> tablo adı => bu istekte atılan sorgu sayısı */
    private array $countsByTable = [];

    public function __construct(
        private readonly int $maxQueriesPerTable = 10,
    ) {
    }

    /**
     * @throws MaxQueriesExceededException limit bu tablo için aşıldıysa
     */
    public function increment(string $table): void
    {
        $count = ($this->countsByTable[$table] ?? 0) + 1;
        $this->countsByTable[$table] = $count;

        if ($count > $this->maxQueriesPerTable) {
            throw MaxQueriesExceededException::forTable($table, $count, $this->maxQueriesPerTable);
        }
    }

    /**
     * Yeni bir HTTP isteğinin başında sayaçları temizler (bkz.
     * QueryCounterRequestListener). Önceki isteğin sayımı bir sonrakine
     * sızmamalıdır — aksi halde PHP-FPM worker'ları arası (veya aynı
     * worker'da art arda gelen istekler arası) yanlış pozitifler oluşur.
     */
    public function reset(): void
    {
        $this->countsByTable = [];
    }

    /**
     * @return array<string, int>
     */
    public function getCounts(): array
    {
        return $this->countsByTable;
    }
}
