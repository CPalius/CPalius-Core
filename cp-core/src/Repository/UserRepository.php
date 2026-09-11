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
 * Also implements UserProviderInterface for the security firewall (Symfony shortcut).
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

    public function findOneByEmailVerificationToken(string $token): ?User
    {
        if ($token === '') {
            return null;
        }

        $conn = $this->getEntityManager()->getConnection();
        $id = $conn->fetchOne(
            'SELECT id FROM users WHERE JSON_UNQUOTE(JSON_EXTRACT(data, \'$.email_verification_token\')) = ? LIMIT 1',
            [$token],
        );

        if ($id === false || $id === null) {
            return null;
        }

        return $this->find((int) $id);
    }

    /**
     * Pending approval registrations (inactive + registration_pending_approval).
     *
     * @return list<User>
     */
    public function findPendingApproval(): array
    {
        return $this->createQueryBuilder('u')
            ->andWhere('u.status = :inactive')
            ->setParameter('inactive', User::STATUS_INACTIVE)
            ->orderBy('u.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Lookup by email first, then username (email is always present).
     */
    public function findOneByEmailOrUsername(string $identifier): ?User
    {
        return $this->findOneByEmail($identifier) ?? $this->findOneByUsername($identifier);
    }

    /**
     * Users with the given role id; filtered in PHP for reliable JSON role matching.
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
     * Whether email is taken by another user ($excludeId skips self on edit).
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

    public function isUsernameTakenByAnotherUser(string $username, ?int $excludeId): bool
    {
        if ($username === '') {
            return false;
        }

        $qb = $this->createQueryBuilder('u')
            ->select('COUNT(u.id)')
            ->andWhere('u.username = :username')
            ->setParameter('username', $username);

        if ($excludeId !== null) {
            $qb->andWhere('u.id != :excludeId')
                ->setParameter('excludeId', $excludeId);
        }

        return (int) $qb->getQuery()->getSingleScalarResult() > 0;
    }

    /**
     * Total user count for dashboard card.
     */
    public function countAll(): int
    {
        return (int) $this->createQueryBuilder('u')
            ->select('COUNT(u.id)')
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function findNewestActive(): ?User
    {
        return $this->createQueryBuilder('u')
            ->andWhere('u.status = :status')
            ->setParameter('status', User::STATUS_ACTIVE)
            ->orderBy('u.createdAt', 'DESC')
            ->addOrderBy('u.id', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * User counts grouped by status for dashboard doughnut widget.
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
            throw new UserNotFoundException(sprintf('No user found with email "%s".', $identifier));
        }

        return $user;
    }

    public function refreshUser(UserInterface $user): UserInterface
    {
        if (!$user instanceof User) {
            throw new UnsupportedUserException(sprintf('Invalid user class "%s".', $user::class));
        }

        $freshUser = $this->find($user->getId());

        if ($freshUser === null) {
            throw new UserNotFoundException(sprintf('User with ID #%d no longer exists.', $user->getId()));
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
