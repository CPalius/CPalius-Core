<?php

namespace App\Repository;

use App\Entity\Node;
use App\Entity\NodeFieldIndex;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<Node>
 */
class NodeRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Node::class);
    }

    /**
     * AACP Dashboard "Toplam İçerik" kartı için: tüm tip/durum/dillerdeki
     * Node sayısı (soft-delete edilmişler hariç, SoftDeletableTrait'in
     * varsayılan filtreleme davranışı bu sorguya da uygulanır).
     */
    public function countAll(): int
    {
        return (int) $this->createQueryBuilder('n')
            ->select('COUNT(n.id)')
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Dashboard'daki "Tipe Göre İçerik" stacked-bar widget'ının veri
     * kaynağı — sabit `type` kolonu üzerinden (JSON'a dokunmaz).
     *
     * @return list<array{type: string, count: int}>
     */
    public function countGroupedByType(): array
    {
        $rows = $this->createQueryBuilder('n')
            ->select('n.type AS type, COUNT(n.id) AS count')
            ->andWhere('n.deletedAt IS NULL')
            ->groupBy('n.type')
            ->orderBy('count', 'DESC')
            ->getQuery()
            ->getResult();

        return array_map(static fn (array $row): array => ['type' => $row['type'], 'count' => (int) $row['count']], $rows);
    }

    /**
     * Dashboard'daki "Duruma Göre İçerik" doughnut widget'ının veri kaynağı
     * — sabit `status` kolonu üzerinden (draft/published/scheduled).
     *
     * @return list<array{status: string, count: int}>
     */
    public function countGroupedByStatus(): array
    {
        $rows = $this->createQueryBuilder('n')
            ->select('n.status AS status, COUNT(n.id) AS count')
            ->groupBy('n.status')
            ->orderBy('count', 'DESC')
            ->getQuery()
            ->getResult();

        return array_map(static fn (array $row): array => ['status' => $row['status'], 'count' => (int) $row['count']], $rows);
    }

    /**
     * FrontMenuRuntime::resolveUrl() için: bir menünün tüm öğelerinin
     * nodeId'lerini TEK sorguda çeker (id => Node haritası olarak) — öğe
     * başına ayrı find() çağrısı yerine (bkz. o sınıfın docblock'u, aynı
     * N+1 deseni MenuItem tarafında da vardı). Soft-delete/durum filtresi
     * BİLİNÇLİ OLARAK burada uygulanmaz: resolveUrl() zaten silinmiş/
     * yayından kalkmış Node'ları sessizce atlıyor, bu karar tek bir yerde
     * (çağıran taraf) kalmalı.
     *
     * @param list<int> $nodeIds
     *
     * @return array<int, Node>
     */
    public function findByIdsIndexed(array $nodeIds): array
    {
        if ($nodeIds === []) {
            return [];
        }

        $nodes = $this->createQueryBuilder('n')
            ->andWhere('n.id IN (:ids)')
            ->setParameter('ids', $nodeIds)
            ->getQuery()
            ->getResult();

        $indexed = [];
        foreach ($nodes as $node) {
            $indexed[$node->getId()] = $node;
        }

        return $indexed;
    }

    /**
     * Belirli bir slug + locale kombinasyonunun kullanımda olup olmadığını
     * kontrol eder. SlugGenerator'ın benzersizlik doğrulaması için kullanılır.
     */
    public function slugExists(string $slug, string $locale, ?int $excludeId = null): bool
    {
        $qb = $this->createQueryBuilder('n')
            ->select('COUNT(n.id)')
            ->andWhere('n.slug = :slug')
            ->andWhere('n.locale = :locale')
            ->setParameter('slug', $slug)
            ->setParameter('locale', $locale);

        if ($excludeId !== null) {
            $qb->andWhere('n.id != :excludeId')
                ->setParameter('excludeId', $excludeId);
        }

        return (int) $qb->getQuery()->getSingleScalarResult() > 0;
    }

    /**
     * Aktif dile göre, slug ile tek bir yayınlanmış içeriği bulur.
     * Front-end controller'ların temel sorgusu budur: "/tr/hakkimizda"
     * geldiğinde locale=tr + slug=hakkimizda + status=published eşleşmesi.
     */
    public function findOnePublishedBySlugAndLocale(string $slug, string $locale): ?Node
    {
        return $this->createQueryBuilder('n')
            ->andWhere('n.slug = :slug')
            ->andWhere('n.locale = :locale')
            ->andWhere('n.status = :status')
            ->andWhere('n.deletedAt IS NULL')
            ->setParameter('slug', $slug)
            ->setParameter('locale', $locale)
            ->setParameter('status', Node::STATUS_PUBLISHED)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Published node by slug in any locale (locale switch fallback).
     */
    public function findOnePublishedBySlug(string $slug, ?string $type = null): ?Node
    {
        $qb = $this->createQueryBuilder('n')
            ->andWhere('n.slug = :slug')
            ->andWhere('n.status = :status')
            ->andWhere('n.deletedAt IS NULL')
            ->setParameter('slug', $slug)
            ->setParameter('status', Node::STATUS_PUBLISHED)
            ->orderBy('n.id', 'ASC')
            ->setMaxResults(1);

        if ($type !== null && $type !== '') {
            $qb->andWhere('n.type = :type')->setParameter('type', $type);
        }

        return $qb->getQuery()->getOneOrNullResult();
    }

    /**
     * Bir içeriğin AYNI translation_group_id'yi paylaşan tüm dil
     * çevirilerini döner. Örn. "Hakkımızda" sayfasının tr+en Node
     * kayıtlarının ikisini birden almak için kullanılır (dil değiştirme
     * linki, hreflang etiketleri vb. için).
     *
     * @return list<Node>
     */
    public function findTranslations(Uuid $translationGroupId): array
    {
        return $this->createQueryBuilder('n')
            ->andWhere('n.translationGroupId = :groupId')
            ->setParameter('groupId', $translationGroupId, 'uuid')
            ->getQuery()
            ->getResult();
    }

    /**
     * findTranslations()'un tek fark: aynı gruptaki BAŞKA bir dildeki
     * çeviriyi bulur. "Bu sayfanın İngilizcesi var mı?" sorusuna cevap
     * verir — dil değiştirici linkinde kullanılır.
     */
    public function findTranslation(Uuid $translationGroupId, string $targetLocale): ?Node
    {
        return $this->createQueryBuilder('n')
            ->andWhere('n.translationGroupId = :groupId')
            ->andWhere('n.locale = :locale')
            ->setParameter('groupId', $translationGroupId, 'uuid')
            ->setParameter('locale', $targetLocale)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Belirli bir tipe (ör. 'post') ve dile göre, yayınlanmış içerikleri
     * en yeniden eskiye sıralı listeler. Blog listesi gibi sayfalar için.
     *
     * @return list<Node>
     */
    public function findPublishedByTypeAndLocale(string $type, string $locale, int $limit = 20, int $offset = 0): array
    {
        return $this->createQueryBuilder('n')
            ->andWhere('n.type = :type')
            ->andWhere('n.locale = :locale')
            ->andWhere('n.status = :status')
            ->setParameter('type', $type)
            ->setParameter('locale', $locale)
            ->setParameter('status', Node::STATUS_PUBLISHED)
            ->orderBy('n.publishedAt', 'DESC')
            ->setMaxResults($limit)
            ->setFirstResult($offset)
            ->getQuery()
            ->getResult();
    }

    /**
     * "blog.publish_scheduled" cron görevinin (bkz.
     * Modules\Blog\Cron\PublishScheduledPostsTask) tek veri kaynağı: durumu
     * STATUS_SCHEDULED olan VE yayın tarihi (publishedAt) şu ana kadar
     * gelmiş (<=) tüm Node'ları döner. Tip veya modülle sınırlanmaz —
     * Node çekirdek bir kavram olduğu için (bkz. sınıfın kendi docblock'u)
     * bu sorgu tüm content type'ları kapsar.
     *
     * @return list<Node>
     */
    public function findDueScheduledNodes(?\DateTimeImmutable $now = null): array
    {
        return $this->createQueryBuilder('n')
            ->andWhere('n.status = :status')
            ->andWhere('n.publishedAt IS NOT NULL')
            ->andWhere('n.publishedAt <= :now')
            ->setParameter('status', Node::STATUS_SCHEDULED)
            ->setParameter('now', $now ?? new \DateTimeImmutable())
            ->getQuery()
            ->getResult();
    }

    /**
     * AACP Sistem Monitörü'ndeki "Zamanlanmış Yayın" widget'ları için:
     * durumu hâlâ STATUS_SCHEDULED olan (yayın zamanı gelmiş veya
     * gelmemiş, hepsi) içerik SAYISINI döner. COUNT ile tek skaler değer
     * okur — widget'ın tüm Node listesini çekmesine gerek yoktur.
     *
     * $type verilirse (ör. Blog modülünün 'post' widget'ı) sadece o
     * content type'a göre daraltılır; null ise tüm type'lar toplanır
     * (cp-core'un genel amaçlı widget'ı için).
     */
    public function countPendingScheduledNodes(?string $type = null): int
    {
        $qb = $this->createQueryBuilder('n')
            ->select('COUNT(n.id)')
            ->andWhere('n.status = :status')
            ->setParameter('status', Node::STATUS_SCHEDULED);

        if ($type !== null) {
            $qb->andWhere('n.type = :type')->setParameter('type', $type);
        }

        return (int) $qb->getQuery()->getSingleScalarResult();
    }

    /**
     * findPublishedByTypeAndLocale()'in QueryBuilder döndüren hâli — sabit
     * bir dizi yerine App\Core\Pagination\Paginator::paginate()'e verilmek
     * üzere tasarlanmıştır (limit/offset ÇAĞIRAN tarafça, Paginator
     * içinde uygulanır). Blog ana listesi (/blog) ve kategori/etiket
     * listeleri bu tek metodu paylaşır; SADECE Node'un sabit kolonları
     * (type, locale, status) üzerinden filtreler — JOIN yoktur, bu yüzden
     * Paginator'ın fetch-join COUNT düzeltmesine ihtiyaç duymaz.
     */
    public function createPublishedByTypeAndLocaleQueryBuilder(string $type, string $locale): QueryBuilder
    {
        return $this->createQueryBuilder('n')
            ->andWhere('n.type = :type')
            ->andWhere('n.locale = :locale')
            ->andWhere('n.status = :status')
            ->andWhere('n.deletedAt IS NULL')
            ->setParameter('type', $type)
            ->setParameter('locale', $locale)
            ->setParameter('status', Node::STATUS_PUBLISHED)
            ->orderBy('n.publishedAt', 'DESC');
    }

    /**
     * Belirli bir kategoriye (n.categories many-to-many) ait, yayınlanmış
     * içerikleri döndüren QueryBuilder. innerJoin bire-çok olduğundan
     * (bir Node birden fazla kategoriye ait olabilir) Paginator'a
     * fetchJoinCollection açık geçirilerek COUNT doğru hesaplanır.
     */
    public function createPublishedByCategoryQueryBuilder(int $categoryId, string $type, string $locale): QueryBuilder
    {
        return $this->createQueryBuilder('n')
            ->innerJoin('n.categories', 'c')
            ->andWhere('n.type = :type')
            ->andWhere('n.locale = :locale')
            ->andWhere('n.status = :status')
            ->andWhere('n.deletedAt IS NULL')
            ->andWhere('c.id = :categoryId')
            ->setParameter('type', $type)
            ->setParameter('locale', $locale)
            ->setParameter('status', Node::STATUS_PUBLISHED)
            ->setParameter('categoryId', $categoryId)
            ->orderBy('n.publishedAt', 'DESC');
    }

    /**
     * Belirli bir etikete (n.tags many-to-many) ait, yayınlanmış içerikleri
     * döndüren QueryBuilder — createPublishedByCategoryQueryBuilder ile
     * aynı gerekçe (bire-çok join + Paginator fetchJoinCollection).
     */
    public function createPublishedByTagQueryBuilder(string $tagSlug, string $type, string $locale): QueryBuilder
    {
        return $this->createQueryBuilder('n')
            ->innerJoin('n.tags', 't')
            ->andWhere('n.type = :type')
            ->andWhere('n.locale = :locale')
            ->andWhere('n.status = :status')
            ->andWhere('n.deletedAt IS NULL')
            ->andWhere('t.slug = :slug')
            ->setParameter('type', $type)
            ->setParameter('locale', $locale)
            ->setParameter('status', Node::STATUS_PUBLISHED)
            ->setParameter('slug', $tagSlug)
            ->orderBy('n.publishedAt', 'DESC');
    }

    /**
     * Modules\Blog\Plugin\BlogArchivePlugin'in tek veri kaynağı: yayınlanmış
     * içerikleri publishedAt'ın yıl/ay bileşenine göre gruplayıp kronolojik
     * (en yeni önce) bir özet döner — createPublishedByDateRangeQueryBuilder()
     * ile birlikte "sidebar'da yıl/ay listesi -> tıklanınca o aya filtrelenmiş
     * arşiv sayfası" akışını besler.
     *
     * DQL'in YEAR()/MONTH() fonksiyonları BİLİNÇLİ OLARAK KULLANILMAZ:
     * bunlar SQL seviyesinde MySQL/Postgres'te var olsa da Doctrine'in
     * temel DQL dilinde (ekstra bir custom fonksiyon kaydı olmadan)
     * TANIMLI DEĞİLDİR ve "Expected known function, got 'YEAR'" hatasıyla
     * patlar (bkz. bu metodun ilk halinde alınan gerçek hata, Faz 3
     * fail-safe testinde module_quarantine.log'a düştü). Ekstra bir DQL
     * fonksiyon kaydı (yeni bir soyutlama katmanı) yerine, blog'un yıllık
     * arşiv hacmi doğası gereği küçük olduğundan (bir blogda binlerce
     * farklı ay olmaz), tüm yayınlanmış publishedAt değerleri tek bir
     * sorguyla çekilip PHP tarafında gruplanır — hem taşınabilir hem basit.
     *
     * @return list<array{year: int, month: int, count: int}>
     */
    public function findPublishedArchiveGroups(string $type, string $locale): array
    {
        $publishedDates = $this->createQueryBuilder('n')
            ->select('n.publishedAt AS publishedAt')
            ->andWhere('n.type = :type')
            ->andWhere('n.locale = :locale')
            ->andWhere('n.status = :status')
            ->andWhere('n.deletedAt IS NULL')
            ->andWhere('n.publishedAt IS NOT NULL')
            ->setParameter('type', $type)
            ->setParameter('locale', $locale)
            ->setParameter('status', Node::STATUS_PUBLISHED)
            ->getQuery()
            ->getResult();

        $counts = [];
        foreach ($publishedDates as $row) {
            /** @var \DateTimeImmutable $publishedAt */
            $publishedAt = $row['publishedAt'];
            $key = $publishedAt->format('Y-m');
            $counts[$key] = ($counts[$key] ?? 0) + 1;
        }

        krsort($counts);

        $groups = [];
        foreach ($counts as $key => $count) {
            [$year, $month] = array_map('intval', explode('-', $key));
            $groups[] = ['year' => $year, 'month' => $month, 'count' => $count];
        }

        return $groups;
    }

    /**
     * findPublishedArchiveGroups()'un ürettiği bir yıl/ay grubuna tıklanınca
     * açılan arşiv sayfasının veri kaynağı — createPublishedByCategoryQueryBuilder
     * ile aynı QueryBuilder-döndüren imza deseni (Paginator::paginate()'e
     * verilmek üzere).
     *
     * findPublishedArchiveGroups() ile aynı gerekçeyle YEAR()/MONTH() DQL
     * fonksiyonları KULLANILMAZ; bunun yerine ayın başlangıcı/bitişi PHP
     * tarafında \DateTimeImmutable ile hesaplanıp standart bir BETWEEN
     * aralığına çevrilir — bu, DQL'in çekirdek dilinde her zaman
     * desteklenen, veritabanı bağımsız bir karşılaştırmadır.
     */
    public function createPublishedByDateRangeQueryBuilder(string $type, string $locale, int $year, int $month): QueryBuilder
    {
        $rangeStart = new \DateTimeImmutable(sprintf('%04d-%02d-01 00:00:00', $year, $month));
        $rangeEnd = $rangeStart->modify('first day of next month');

        return $this->createQueryBuilder('n')
            ->andWhere('n.type = :type')
            ->andWhere('n.locale = :locale')
            ->andWhere('n.status = :status')
            ->andWhere('n.deletedAt IS NULL')
            ->andWhere('n.publishedAt >= :rangeStart')
            ->andWhere('n.publishedAt < :rangeEnd')
            ->setParameter('type', $type)
            ->setParameter('locale', $locale)
            ->setParameter('status', Node::STATUS_PUBLISHED)
            ->setParameter('rangeStart', $rangeStart)
            ->setParameter('rangeEnd', $rangeEnd)
            ->orderBy('n.publishedAt', 'DESC');
    }

    /**
     * Başlıkta serbest metin araması. Bilinçli olarak SADECE n.title (sabit
     * SQL kolonu) üzerinden filtreler: Node::data['excerpt']/['body'] JSON
     * içinde tutulur ve JSON içi LIKE taraması indekslenemez, büyük
     * tablolarda tam tablo taraması anlamına gelir (Manifesto Law 6.3'ün
     * ruhu — sorgulanabilir her şey ya sabit kolon ya da flat index olmalı).
     * Zengin bir arama deneyimi gerekiyorsa gelecekte ayrı bir arama
     * indeksi (ör. NodeFieldIndex'e 'search_text' alanı) eklenmelidir.
     */
    public function createSearchQueryBuilder(string $searchTerm, string $type, string $locale): QueryBuilder
    {
        return $this->createQueryBuilder('n')
            ->andWhere('n.type = :type')
            ->andWhere('n.locale = :locale')
            ->andWhere('n.status = :status')
            ->andWhere('n.deletedAt IS NULL')
            ->andWhere('n.title LIKE :term')
            ->setParameter('type', $type)
            ->setParameter('locale', $locale)
            ->setParameter('status', Node::STATUS_PUBLISHED)
            ->setParameter('term', '%'.addcslashes($searchTerm, '%_').'%')
            ->orderBy('n.publishedAt', 'DESC');
    }

    /**
     * Bir yazının detay sayfasındaki "İlgili Yazılar" widget'ı için: aynı
     * birincil kategoriyi (Node::$category) paylaşan, kendisi HARİÇ, en
     * güncel yayınlanmış diğer yazıları döner. Node::$categories (çoklu
     * ManyToMany) yerine bilinçli olarak $category (birincil kategori)
     * kullanılır — "ilgili" tanımı burada tek ve net bir sinyale
     * dayanmalıdır, çoklu kategori kesişimi gereksiz karmaşıklık katardı.
     * $category null ise (kategorisiz yazı) boş dizi döner.
     *
     * @return list<Node>
     */
    public function findRelatedPosts(Node $post, int $limit = 3): array
    {
        $category = $post->getCategory();
        if ($category === null) {
            return [];
        }

        return $this->createQueryBuilder('n')
            ->andWhere('n.type = :type')
            ->andWhere('n.locale = :locale')
            ->andWhere('n.status = :status')
            ->andWhere('n.deletedAt IS NULL')
            ->andWhere('n.category = :categoryId')
            ->andWhere('n.id != :excludeId')
            ->setParameter('type', $post->getType())
            ->setParameter('locale', $post->getLocale())
            ->setParameter('status', Node::STATUS_PUBLISHED)
            ->setParameter('categoryId', $category->getId())
            ->setParameter('excludeId', $post->getId())
            ->orderBy('n.publishedAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * CPalius Manifesto 3.3 (High-Performance Querying): Node::data JSON
     * kolonuna dokunmadan, NodeFieldIndex "düz indeks" tablosuna join
     * atarak dinamik bir alanı standart SQL karşılaştırma operatörleriyle
     * (=, <, <=, >, >=, !=) filtreler.
     *
     * Örnek: "'product' tipinde, 'price' değeri 1500'den küçük, 'tr'
     * dilinde yayınlanmış tüm node'lar":
     *
     *   $nodeRepository->findByIndexedField(
     *       type: 'product',
     *       locale: 'tr',
     *       fieldName: 'price',
     *       value: '1500',
     *       valueColumn: 'valueDecimal',
     *       operator: '<',
     *   );
     *
     * $valueColumn, NodeFieldIndex'te sorgulanan alanın hangi value*
     * kolonuna endekslendiğini belirtir ('valueString'|'valueInt'|
     * 'valueDecimal'|'valueDatetime') — çağıran taraf bunu
     * QueryableFieldsRegistry::getFieldsForType() üzerinden bilir.
     *
     * @return list<Node>
     */
    public function findByIndexedField(
        string $type,
        string $locale,
        string $fieldName,
        mixed $value,
        string $valueColumn = 'valueString',
        string $operator = '=',
        int $limit = 20,
        int $offset = 0,
    ): array {
        $allowedColumns = ['valueString', 'valueInt', 'valueDecimal', 'valueDatetime'];
        if (!\in_array($valueColumn, $allowedColumns, true)) {
            throw new \InvalidArgumentException(sprintf('Geçersiz valueColumn: "%s"', $valueColumn));
        }

        $allowedOperators = ['=', '!=', '<', '<=', '>', '>='];
        if (!\in_array($operator, $allowedOperators, true)) {
            throw new \InvalidArgumentException(sprintf('Geçersiz operator: "%s"', $operator));
        }

        return $this->createQueryBuilder('n')
            ->innerJoin(NodeFieldIndex::class, 'idx', 'WITH', 'idx.node = n.id')
            ->andWhere('n.type = :type')
            ->andWhere('n.locale = :locale')
            ->andWhere('n.status = :status')
            ->andWhere('idx.fieldName = :fieldName')
            ->andWhere(sprintf('idx.%s %s :value', $valueColumn, $operator))
            ->setParameter('type', $type)
            ->setParameter('locale', $locale)
            ->setParameter('status', Node::STATUS_PUBLISHED)
            ->setParameter('fieldName', $fieldName)
            ->setParameter('value', $value)
            ->orderBy('n.publishedAt', 'DESC')
            ->setMaxResults($limit)
            ->setFirstResult($offset)
            ->getQuery()
            ->getResult();
    }

    /**
     * ProcessWire'ın "sihirli sorgu" (Selector API) fikrinden ilham alan,
     * insan tarafından okunabilir tek bir string ile Node sorgulama arayüzü:
     *
     *   $nodeRepository->findNodesBySelector('type=post, status=published, limit=5, sort=created_at:desc');
     *   $nodeRepository->findNodesBySelector('type=product, price<1500, locale=tr');
     *
     * Format: virgülle ayrılmış "anahtar=deger" (veya "anahtar<deger",
     * "anahtar>deger" vb.) ikilileri. Desteklenen operatörler: =, !=, <,
     * <=, >, >= (sıralama: en uzun operatör önce denenir, böylece "!="
     * "!"+"=" olarak yanlış bölünmez).
     *
     * İki anahtar sınıfı vardır:
     *   1) Sabit Node kolonları (type, status, locale, slug, category) ->
     *      doğrudan n.<alan> üzerinden filtrelenir.
     *   2) Modifier'lar (limit, offset, sort) -> sorgunun sayfalama/sıralama
     *      davranışını belirler, WHERE'e dahil edilmez.
     *   3) Yukarıdakilerin dışındaki her anahtar, Manifesto Law 3.3 (High-
     *      Performance Flat Field Index) gereği NodeFieldIndex tablosuna
     *      join atılarak dinamik bir alan olarak sorgulanır — value_string/
     *      value_int/value_decimal kolonundan hangisinin kullanılacağı,
     *      verilen değerin PHP tipinden (sayısal mı, ondalıklı mı, düz
     *      string mi) otomatik çıkarılır.
     *
     * Bozuk/tanınmayan bir parça (ör. "=" içermeyen bir segment) sessizce
     * ATLANIR — RoleConfigManager'daki fail-safe felsefesiyle aynı: bir
     * yazım hatası tüm sorguyu patlatmak yerine sadece o filtreyi yok sayar.
     *
     * @return list<Node>
     */
    public function findNodesBySelector(string $selector): array
    {
        $qb = $this->createQueryBuilder('n');

        $limit = 20;
        $offset = 0;
        $sortField = 'createdAt';
        $sortDirection = 'DESC';
        $joinedIndex = false;
        $conditionIndex = 0;

        foreach ($this->parseSelectorParts($selector) as [$key, $operator, $rawValue]) {
            switch ($key) {
                case 'limit':
                    $limit = max(0, (int) $rawValue);
                    break;

                case 'offset':
                    $offset = max(0, (int) $rawValue);
                    break;

                case 'sort':
                    [$sortField, $sortDirection] = $this->parseSort($rawValue);
                    break;

                case 'type':
                case 'status':
                case 'locale':
                case 'slug':
                    $paramName = sprintf('p%d', $conditionIndex++);
                    $qb->andWhere(sprintf('n.%s %s :%s', $key, $operator, $paramName))
                        ->setParameter($paramName, $rawValue);
                    break;

                case 'category':
                    $paramName = sprintf('p%d', $conditionIndex++);
                    $qb->andWhere(sprintf('n.category %s :%s', $operator, $paramName))
                        ->setParameter($paramName, (int) $rawValue);
                    break;

                default:
                    // Bilinmeyen anahtar: Node'un sabit kolonu değil,
                    // dinamik (JSON->flat index) bir alan olarak ele al.
                    if (!$joinedIndex) {
                        $qb->innerJoin(NodeFieldIndex::class, 'idx', 'WITH', 'idx.node = n.id');
                        $joinedIndex = true;
                    }

                    $fieldParam = sprintf('fname%d', $conditionIndex);
                    $valueParam = sprintf('fval%d', $conditionIndex);
                    $conditionIndex++;

                    $valueColumn = $this->guessValueColumn($rawValue);

                    $qb->andWhere(sprintf('idx.fieldName = :%s', $fieldParam))
                        ->andWhere(sprintf('idx.%s %s :%s', $valueColumn, $operator, $valueParam))
                        ->setParameter($fieldParam, $key)
                        ->setParameter($valueParam, $this->castIndexValue($rawValue, $valueColumn));
                    break;
            }
        }

        $allowedSortFields = ['createdAt', 'updatedAt', 'publishedAt', 'title', 'slug'];
        if (!\in_array($sortField, $allowedSortFields, true)) {
            $sortField = 'createdAt';
        }

        $qb->orderBy('n.'.$sortField, $sortDirection)
            ->setMaxResults($limit)
            ->setFirstResult($offset);

        return $qb->getQuery()->getResult();
    }

    /**
     * "type=post, status=published, limit=5" gibi bir selector string'ini
     * [anahtar, operatör, değer] üçlülerine ayrıştırır.
     *
     * @return list<array{0: string, 1: string, 2: string}>
     */
    private function parseSelectorParts(string $selector): array
    {
        // En uzun operatör önce denenmeli: "!=" ve "<=" gibi iki karakterli
        // operatörler, tek karakterli "=" veya "<" ile yanlış bölünmemeli.
        $operators = ['!=', '<=', '>=', '=', '<', '>'];

        $parts = [];

        foreach (explode(',', $selector) as $segment) {
            $segment = trim($segment);
            if ($segment === '') {
                continue;
            }

            foreach ($operators as $operator) {
                $pos = strpos($segment, $operator);
                if ($pos === false) {
                    continue;
                }

                $key = trim(substr($segment, 0, $pos));
                $value = trim(substr($segment, $pos + \strlen($operator)));

                if ($key === '') {
                    continue 2;
                }

                $parts[] = [$key, $operator, $value];
                continue 2;
            }

            // Hiçbir operatör bulunamadı: bu segment sessizce atlanır
            // (fail-safe — bkz. metod dokümantasyonu).
        }

        return $parts;
    }

    /**
     * "created_at:desc" -> ['createdAt', 'DESC']. Yön verilmezse (ör.
     * sadece "created_at") varsayılan DESC kullanılır. Alan adı snake_case
     * geldiğinde Node entity'sinin camelCase property'sine çevrilir.
     *
     * @return array{0: string, 1: string}
     */
    private function parseSort(string $rawValue): array
    {
        $pieces = explode(':', $rawValue, 2);
        $field = trim($pieces[0]);
        $direction = isset($pieces[1]) ? strtoupper(trim($pieces[1])) : 'DESC';

        if (!\in_array($direction, ['ASC', 'DESC'], true)) {
            $direction = 'DESC';
        }

        $camelField = lcfirst(str_replace(' ', '', ucwords(str_replace('_', ' ', $field))));

        return [$camelField !== '' ? $camelField : 'createdAt', $direction];
    }

    /**
     * Bir selector değerinin, NodeFieldIndex'te hangi value* kolonuna karşı
     * karşılaştırılacağını değerin GÖRÜNÜMÜNDEN tahmin eder: tamsayı
     * görünüyorsa valueInt, ondalıklı görünüyorsa valueDecimal, aksi halde
     * valueString. Tarih tahmini bilinçli olarak yapılmaz (ISO 8601 bir
     * string olarak da valueString ile hâlâ eşit/karşılaştırma çalışır);
     * hassas tarih filtreleme gerekiyorsa findByIndexedField() doğrudan
     * kullanılmalıdır.
     */
    private function guessValueColumn(string $rawValue): string
    {
        if (preg_match('/^-?\d+$/', $rawValue) === 1) {
            return 'valueInt';
        }

        if (preg_match('/^-?\d+\.\d+$/', $rawValue) === 1) {
            return 'valueDecimal';
        }

        return 'valueString';
    }

    private function castIndexValue(string $rawValue, string $valueColumn): int|string
    {
        return match ($valueColumn) {
            'valueInt' => (int) $rawValue,
            default => $rawValue,
        };
    }
}
