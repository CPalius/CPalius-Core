<?php

declare(strict_types=1);

namespace App\Core\Migrate;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\Decorator\EntityManagerDecorator;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * The EntityManager that import destinations actually talk to.
 *
 * A failed flush (unique index, dead connection) closes Doctrine's manager for
 * the rest of the request. The runner treats that as one row, but every later
 * row then dies with "The EntityManager is closed." This wrapper always
 * delegates to the current open manager, resetting it when the last flush
 * killed it, so one bad username does not take the other 899 users with it.
 */
final class ImportEntityManager extends EntityManagerDecorator
{
    public function __construct(
        private readonly ManagerRegistry $doctrine,
    ) {
        parent::__construct($this->current());
    }

    public function persist(object $object): void
    {
        $this->current()->persist($object);
    }

    public function remove(object $object): void
    {
        $this->current()->remove($object);
    }

    public function flush(): void
    {
        $this->current()->flush();
    }

    public function clear(): void
    {
        $this->current()->clear();
    }

    public function find(string $className, mixed $id, LockMode|int|null $lockMode = null, int|null $lockVersion = null): object|null
    {
        return $this->current()->find($className, $id, $lockMode, $lockVersion);
    }

    public function getRepository(string $className): EntityRepository
    {
        return $this->current()->getRepository($className);
    }

    public function getConnection(): Connection
    {
        return $this->current()->getConnection();
    }

    public function createQueryBuilder(): QueryBuilder
    {
        return $this->current()->createQueryBuilder();
    }

    public function isOpen(): bool
    {
        return $this->current()->isOpen();
    }

    public function contains(object $object): bool
    {
        return $this->current()->contains($object);
    }

    public function refresh(object $object, LockMode|int|null $lockMode = null): void
    {
        $this->current()->refresh($object, $lockMode);
    }

    private function current(): EntityManagerInterface
    {
        $em = $this->doctrine->getManager();

        if (!$em instanceof EntityManagerInterface) {
            throw new \LogicException('The default Doctrine manager is not an EntityManager.');
        }

        if (!$em->isOpen()) {
            $em = $this->doctrine->resetManager();

            if (!$em instanceof EntityManagerInterface) {
                throw new \LogicException('resetManager() did not return an EntityManager.');
            }
        }

        $this->wrapped = $em;

        return $em;
    }
}
