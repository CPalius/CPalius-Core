<?php

namespace App\Repository;

use App\Entity\Node;
use App\Entity\Tag;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\String\Slugger\AsciiSlugger;

/**
 * @extends ServiceEntityRepository<Tag>
 */
class TagRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Tag::class);
    }

    public function findOneBySlug(string $slug, string $locale): ?Tag
    {
        return $this->findOneBy(['slug' => $slug, 'locale' => $locale]);
    }

    /**
     * AACP Dashboard "Etiketler" kartı için toplam sayı (tüm diller).
     */
    public function countAll(): int
    {
        return (int) $this->createQueryBuilder('t')
            ->select('COUNT(t.id)')
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Modules\Blog\Plugin\BlogWidgetPlugin'in "Popüler Etiketler" bloğu
     * için: Node::tags (node_tag join table) üzerinden, SADECE yayınlanmış
     * içeriklere bağlı etiketleri kullanım sayısına göre azalan sırada
     * döner. Taslak/silinmiş Node'lara bağlı etiketler bilinçli olarak
     * sayılmaz (n.status = 'published' filtresi) — aksi halde bir yazar
     * taslak halindeki bir gönderiye ekliği bir etiketi henüz kimse
     * görmeden "popüler" olarak ön yüzde teşhir edilebilirdi.
     *
     * Node::tags TEK YÖNLÜ (unidirectional) bir ManyToMany'dir — Tag
     * tarafında bir ters ilişki (inverse side) TANIMLI DEĞİLDİR (bkz.
     * Tag.php: sınıfta hiçbir ManyToMany alanı yok). Bu yüzden DQL
     * sorgusu BİLİNÇLİ OLARAK Node::class'tan (ilişkinin sahibi/owning
     * side) başlar, "t MEMBER OF n.tags" DEĞİL "n.tags" üzerinden doğrudan
     * innerJoin kurulur — Tag tarafından başlayan bir "t.nodes" erişimi
     * mapping'de yok, DQL hata verirdi.
     *
     * @return list<array{tag: Tag, usageCount: int}>
     */
    public function findMostUsed(string $locale, int $limit = 10): array
    {
        // DQL'de FROM'daki root entity (n) SELECT listesinde hiç yer
        // almadan başka bir join edilmiş entity'yi (t) tam nesne olarak
        // seçmek Doctrine'de "Cannot select entity through identification
        // variables without choosing at least one root entity alias"
        // hatası verir (bkz. bu metodun ilk iki denemesinde gerçekten
        // alınan ve module_quarantine.log'a düşen hatalar). Bu yüzden
        // sorgu SADECE skaler alanlar (t.id + COUNT) seçer; gerçek Tag
        // nesneleri aşağıda tek bir ikinci sorguyla (findBy, IN listesi)
        // hydrate edilir — limit küçük (varsayılan 10) olduğundan bu N+1
        // değil, iki sabit sorguluk bir maliyettir.
        $rows = $this->getEntityManager()->createQueryBuilder()
            ->select('t.id AS tagId, COUNT(n.id) AS usageCount')
            ->from(Node::class, 'n')
            ->innerJoin('n.tags', 't')
            ->andWhere('t.locale = :locale')
            ->andWhere('n.status = :status')
            ->setParameter('locale', $locale)
            ->setParameter('status', Node::STATUS_PUBLISHED)
            ->groupBy('t.id')
            ->orderBy('usageCount', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        if ($rows === []) {
            return [];
        }

        $usageCountByTagId = [];
        foreach ($rows as $row) {
            $usageCountByTagId[(int) $row['tagId']] = (int) $row['usageCount'];
        }

        $tags = $this->findBy(['id' => array_keys($usageCountByTagId)]);

        $result = [];
        foreach ($tags as $tag) {
            $result[] = ['tag' => $tag, 'usageCount' => $usageCountByTagId[$tag->getId()]];
        }

        usort($result, static fn (array $a, array $b): int => $b['usageCount'] <=> $a['usageCount']);

        return $result;
    }

    /**
     * Serbest metin tag input'undan (virgülle ayrılmış isimler) gelen adları
     * çözer: var olan Tag'i bulur, yoksa yeni bir tane oluşturup persist eder
     * (henüz flush ETMEZ — çağıran taraf tek bir flush ile toplu kaydeder).
     *
     * @param list<string> $names
     *
     * @return list<Tag>
     */
    public function findOrCreateByNames(array $names, string $locale): array
    {
        $slugger = new AsciiSlugger($locale);
        $tags = [];

        foreach ($names as $name) {
            $name = trim($name);
            if ($name === '') {
                continue;
            }

            $baseSlug = strtolower($slugger->slug($name)->toString());
            if ($baseSlug === '') {
                continue;
            }

            $tag = $this->findOneBySlug($baseSlug, $locale);
            if ($tag === null) {
                $tag = new Tag($name, $baseSlug, $locale);
                $this->getEntityManager()->persist($tag);
            }

            $tags[] = $tag;
        }

        return $tags;
    }
}
