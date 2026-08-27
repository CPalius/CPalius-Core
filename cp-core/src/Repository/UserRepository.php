<?php

namespace App\Repository;

use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Security\Core\Exception\UnsupportedUserException;
use Symfony\Component\Security\Core\Exception\UserNotFoundException;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\PasswordUpgraderInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Core\User\UserProviderInterface;

/**
 * @extends ServiceEntityRepository<User>
 *
 * Symfony'nin security firewall'ı için doğrudan bir UserProviderInterface
 * implementasyonu — ayrı bir UserProvider sınıfına gerek yok, entity
 * repository'nin kendisi bu rolü üstlenir (Symfony'nin önerdiği kısayol).
 */
class UserRepository extends ServiceEntityRepository implements UserProviderInterface, PasswordUpgraderInterface
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, User::class);
    }

    public function findOneByEmail(string $email): ?User
    {
        return $this->findOneBy(['email' => $email]);
    }

    public function findOneByUsername(string $username): ?User
    {
        return $this->findOneBy(['username' => $username]);
    }

    /**
     * CpUserProvider için: verilen kimliği ÖNCE e-posta, bulunamazsa
     * kullanıcı adı olarak arar. E-posta önceliklidir çünkü her kullanıcının
     * garanti e-postası vardır (username nullable), bu yüzden e-posta ile
     * eşleşme daha güvenilir bir ilk denemedir.
     */
    public function findOneByEmailOrUsername(string $identifier): ?User
    {
        return $this->findOneByEmail($identifier) ?? $this->findOneByUsername($identifier);
    }

    /**
     * AACP kullanıcı listesi için: e-posta üzerinde basit bir arama
     * (opsiyonel) uygulayarak en yeni kayıtlar önde sıralı bir QueryBuilder
     * döner. Paginator::paginate() bunu setMaxResults/setFirstResult ile
     * sarmalar (bkz. Paginator sınıf üstü doküman notu).
     *
     * Ad/soyad $data JSON kolonunda yaşadığı için (bkz. User::getFirstName())
     * ve projede JSON_EXTRACT gibi bir custom DQL fonksiyonu tanımlı
     * olmadığı için (bkz. cp-core/config/packages/doctrine.yaml), arama
     * bilinçli olarak SADECE e-posta sabit kolonuna uygulanır — bu, DQL'in
     * desteklemediği bir fonksiyonu çağırıp çalışma zamanı hatası üretmek
     * yerine güvenli bir alt kümedir.
     */
    /**
     * "Yöneticiler" ekranı için: verilen rol id'sine (ör. 'admin') sahip
     * kullanıcılar. User::$roles bir JSON sütunudur (bkz. #[ORM\Column(type:
     * 'json')]); veritabanı motoruna göre serileştirme farklılık
     * gösterebileceğinden LIKE ile kaba DB filtresi yerine kullanıcı
     * sayısının admin panelinde büyük olmayacağı varsayımıyla PHP tarafında
     * getCpaliusRoles() ile KESİN filtreleme yapılır — bu, JSON encoding
     * ayrıntılarına bağımlı kırılgan bir sorgudan daha güvenilirdir.
     *
     * @return list<User>
     */
    public function findByRole(string $roleId): array
    {
        return array_values(array_filter(
            $this->findBy([], ['createdAt' => 'DESC']),
            static fn (User $user): bool => \in_array($roleId, $user->getCpaliusRoles(), true),
        ));
    }

    public function createAdminListQueryBuilder(?string $search = null): \Doctrine\ORM\QueryBuilder
    {
        $qb = $this->createQueryBuilder('u')
            ->orderBy('u.createdAt', 'DESC');

        $search = trim((string) $search);
        if ($search !== '') {
            $qb->andWhere('u.email LIKE :search')
                ->setParameter('search', '%'.$search.'%');
        }

        return $qb;
    }

    /**
     * Bir e-postanın BAŞKA bir kullanıcı tarafından zaten kullanılıp
     * kullanılmadığını kontrol eder (edit ekranında "kendi e-postan"
     * çakışma sayılmamalı, bu yüzden $excludeId parametresi vardır).
     */
    public function isEmailTakenByAnotherUser(string $email, ?int $excludeId): bool
    {
        $qb = $this->createQueryBuilder('u')
            ->select('COUNT(u.id)')
            ->andWhere('u.email = :email')
            ->setParameter('email', $email);

        if ($excludeId !== null) {
            $qb->andWhere('u.id != :excludeId')
                ->setParameter('excludeId', $excludeId);
        }

        return (int) $qb->getQuery()->getSingleScalarResult() > 0;
    }

    /**
     * AACP Dashboard "Kullanıcılar" kartı için toplam sayı.
     */
    public function countAll(): int
    {
        return (int) $this->createQueryBuilder('u')
            ->select('COUNT(u.id)')
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Dashboard'daki "Kullanıcı Durumu" doughnut widget'ının veri kaynağı
     * — User::STATUS_* sabitleri (active/inactive/banned) üzerinden.
     *
     * @return list<array{status: string, count: int}>
     */
    public function countGroupedByStatus(): array
    {
        $rows = $this->createQueryBuilder('u')
            ->select('u.status AS status, COUNT(u.id) AS count')
            ->groupBy('u.status')
            ->orderBy('count', 'DESC')
            ->getQuery()
            ->getResult();

        return array_map(static fn (array $row): array => ['status' => $row['status'], 'count' => (int) $row['count']], $rows);
    }

    public function loadUserByIdentifier(string $identifier): UserInterface
    {
        $user = $this->findOneByEmail($identifier);

        if ($user === null) {
            throw new UserNotFoundException(sprintf('"%s" e-postasına sahip bir kullanıcı bulunamadı.', $identifier));
        }

        return $user;
    }

    public function refreshUser(UserInterface $user): UserInterface
    {
        if (!$user instanceof User) {
            throw new UnsupportedUserException(sprintf('Geçersiz kullanıcı sınıfı "%s".', $user::class));
        }

        $freshUser = $this->find($user->getId());

        if ($freshUser === null) {
            throw new UserNotFoundException(sprintf('#%d kimlikli kullanıcı artık mevcut değil.', $user->getId()));
        }

        return $freshUser;
    }

    public function supportsClass(string $class): bool
    {
        return $class === User::class || is_subclass_of($class, User::class);
    }

    public function upgradePassword(PasswordAuthenticatedUserInterface $user, string $newHashedPassword): void
    {
        if (!$user instanceof User) {
            return;
        }

        $user->setPassword($newHashedPassword);
        $this->getEntityManager()->persist($user);
        $this->getEntityManager()->flush();
    }
}
