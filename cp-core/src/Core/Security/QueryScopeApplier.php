<?php

declare(strict_types=1);

namespace App\Core\Security;

use App\Entity\User;
use Doctrine\ORM\QueryBuilder;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * Manifesto Law 6.2 (Voter to SQL): bir liste ekranında 100 satırlık bir
 * sonuç kümesinin her öğesi için ayrı ayrı CPaliusVoter::vote() çağırmak,
 * N+1'e denk bir performans krizidir (voter'ın kendisi N+1 sorgu atmasa
 * bile, PHP tarafında yüzlerce nesneyi bellekte örnekleyip sonra elemek
 * gerekir). QueryScopeApplier bunun yerine yetki kararını sorgu ÇALIŞMADAN
 * ÖNCE, doğrudan SQL WHERE koşuluna çevirir.
 *
 * Akış CPaliusVoter'daki ".own" / ".any" sözleşmesiyle birebir aynıdır:
 *   - Kullanıcı "<capability>.any" yeteneğine sahipse kısıt eklenmez
 *     (tüm satırları görebilir).
 *   - Kullanıcı sadece "<capability>.own" yeteneğine sahipse, sorguya
 *     "AND <ownerAlias>.<ownerField> = :cpScopeCurrentUser" enjekte edilir.
 *   - Kullanıcı ne .any ne .own yeteneğine sahipse (fail-safe), sorgu
 *     hiçbir satır dönmeyecek şekilde daraltılır (1 = 0) — sessizce tüm
 *     tabloyu göstermek YERİNE açıkça boş sonuç tercih edilir.
 */
final class QueryScopeApplier
{
    private const ANY_SUFFIX = '.any';
    private const OWN_SUFFIX = '.own';

    public function __construct(
        private readonly Security $security,
        private readonly RoleConfigManager $roleConfigManager,
    ) {
    }

    /**
     * @param string $capabilityBase Süffiks olmadan yetenek kökü
     *   (ör. "node.post.edit" — ".any"/".own" bu metod tarafından eklenir).
     * @param string $ownerField Sahiplik kısıtı uygulanacak entity alanı
     *   (ör. "author"). $ownerAlias.$ownerField karşılaştırılır.
     */
    public function apply(
        QueryBuilder $qb,
        string $rootAlias,
        string $capabilityBase,
        string $ownerField = 'author',
    ): QueryBuilder {
        $user = $this->security->getUser();

        if (!$user instanceof User || !$user->isActive()) {
            return $this->denyAll($qb);
        }

        $granted = $this->roleConfigManager->getCapabilitiesForRoles($user->getCpaliusRoles());

        if (in_array($capabilityBase.self::ANY_SUFFIX, $granted, true)) {
            return $qb;
        }

        if (in_array($capabilityBase.self::OWN_SUFFIX, $granted, true)) {
            return $qb
                ->andWhere(sprintf('%s.%s = :cpScopeCurrentUser', $rootAlias, $ownerField))
                ->setParameter('cpScopeCurrentUser', $user->getId());
        }

        return $this->denyAll($qb);
    }

    /**
     * Fail-safe: ne .any ne .own yetkisi yoksa sorguyu her zaman boş
     * sonuç dönecek şekilde kilitler. QueryBuilder'ı iptal etmek yerine
     * bunu tercih etmemizin sebebi, çağıran kodun her zaman aynı tip
     * (list<Node> vb.) bir sonuç bekleyebilmesini sağlamaktır.
     */
    private function denyAll(QueryBuilder $qb): QueryBuilder
    {
        return $qb->andWhere('1 = 0');
    }
}
