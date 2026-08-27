<?php

declare(strict_types=1);

namespace App\Core\Pagination;

use Doctrine\ORM\QueryBuilder;
use Doctrine\ORM\Tools\Pagination\Paginator as DoctrinePaginator;

/**
 * KnpPaginatorBundle KURULU DEĞİL (composer.json'da yok) — bu servis onun
 * yerine, projede zaten bağımlılık olarak var olan doctrine/orm'un dahili
 * Doctrine\ORM\Tools\Pagination\Paginator'ını sarmalayan hafif bir katman.
 * Ekstra bir bundle/config yükü olmadan hem Studio (admin) hem tema
 * (frontend) sayfalarında aynı ?page= sözleşmesiyle kullanılır.
 *
 * Doctrine'in dahili Paginator'ı BİLİNÇLİ OLARAK tercih edildi (ör.
 * "$qb->getQuery()->setMaxResults()/setFirstResult()" + ayrı bir
 * "COUNT(*)" sorgusu yazmak yerine): innerJoin('n.categories', ...) gibi
 * bire-çok join'ler yüzünden satır çoğalması olduğunda dahili Paginator
 * fetch-join'i algılayıp COUNT'u otomatik doğru hesaplar — elle yazılan
 * bir COUNT sorgusu bu çoğalmayı gözden kaçırıp yanlış toplam/sayfa
 * sayısı üretebilirdi.
 */
final class Paginator
{
    private const DEFAULT_PER_PAGE = 12;

    /**
     * @template T
     *
     * @param QueryBuilder $queryBuilder ORDER BY dahil, setMaxResults/setFirstResult
     *   ÇAĞRILMAMIŞ bir QueryBuilder — limit/offset burada uygulanır.
     *
     * @return PaginatedResult<T>
     */
    public function paginate(QueryBuilder $queryBuilder, int $page = 1, int $perPage = self::DEFAULT_PER_PAGE): PaginatedResult
    {
        $page = max(1, $page);
        $perPage = max(1, $perPage);

        $query = $queryBuilder
            ->setFirstResult(($page - 1) * $perPage)
            ->setMaxResults($perPage)
            ->getQuery();

        $doctrinePaginator = new DoctrinePaginator($query, fetchJoinCollection: true);

        return new PaginatedResult(
            items: iterator_to_array($doctrinePaginator->getIterator()),
            totalItems: \count($doctrinePaginator),
            currentPage: $page,
            perPage: $perPage,
        );
    }
}
