<?php

declare(strict_types=1);

namespace App\Core\Trash\EventListener;

use App\Core\Trash\Entity\TrashItem;
use App\Core\Trash\TrashPolicy;
use App\Core\Trash\TrashRowCodec;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Events;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

/**
 * Copies every row Doctrine is about to DELETE into cp_trash_items.
 *
 * Runs in onFlush, while the row is still in the table, so the copy is the
 * database's own version of it (every column, including ones the entity does
 * not map) rather than a reconstruction from object state. The TrashItem rows
 * join the same flush, so a delete and its trash copy commit or roll back
 * together — there is no window where one exists without the other.
 *
 * What it cannot see, by construction: bulk DQL/SQL DELETEs (they never reach
 * the unit of work), rows removed by database-level ON DELETE CASCADE, and
 * many-to-many link rows. Delete paths that matter should go through the ORM.
 */
#[AsDoctrineListener(event: Events::onFlush)]
final class TrashCaptureListener
{
    public function __construct(
        private readonly TrashPolicy $policy,
        private readonly TokenStorageInterface $tokenStorage,
        private readonly Connection $connection,
    ) {
    }

    public function onFlush(OnFlushEventArgs $args): void
    {
        $em = $args->getObjectManager();
        $unitOfWork = $em->getUnitOfWork();

        $deletions = $unitOfWork->getScheduledEntityDeletions();
        if ($deletions === [] || $this->policy->isSuspended()) {
            return;
        }

        $batchId = bin2hex(random_bytes(16));
        $userId = $this->currentUserId();
        $trashMetadata = $em->getClassMetadata(TrashItem::class);

        foreach ($deletions as $entity) {
            $metadata = $em->getClassMetadata($entity::class);
            $class = $metadata->getName();

            // ponytail: JOINED inheritance spreads one entity over several tables; capture would need one row per table. Add when such an entity needs restoring.
            if ($metadata->isInheritanceTypeJoined() || !$this->policy->isTrashableClass($class)) {
                continue;
            }

            $resource = $this->policy->resourceName($class);
            if (!$this->policy->isEnabled($resource)) {
                continue;
            }

            $ids = $unitOfWork->getEntityIdentifier($entity);
            $columns = $metadata->getIdentifierColumnNames();
            if ($ids === [] || \count($ids) !== \count($columns)) {
                continue;
            }

            $row = $this->fetchRow($metadata->getTableName(), $columns, array_values($ids));
            if ($row === null) {
                continue;
            }

            $item = new TrashItem(
                $batchId,
                $resource,
                $class,
                $metadata->getTableName(),
                implode('-', array_map(static fn (mixed $v): string => (string) $v, array_values($ids))),
                $this->label($entity, $resource, $ids),
                TrashRowCodec::encode($row),
                $userId,
                $this->policy->expiryFor($resource),
            );

            $em->persist($item);
            $unitOfWork->computeChangeSet($trashMetadata, $item);
        }
    }

    /**
     * @param list<string> $columns
     * @param list<mixed>  $values
     *
     * @return array<string, mixed>|null
     */
    private function fetchRow(string $table, array $columns, array $values): ?array
    {
        $where = implode(' AND ', array_map(
            fn (string $c): string => $this->connection->quoteIdentifier($c).' = ?',
            $columns,
        ));

        try {
            $row = $this->connection->fetchAssociative(
                'SELECT * FROM '.$this->connection->quoteIdentifier($table).' WHERE '.$where,
                $values,
            );
        } catch (\Throwable) {
            return null;
        }

        return $row === false ? null : $row;
    }

    /**
     * @param array<string, mixed> $ids
     */
    private function label(object $entity, string $resource, array $ids): string
    {
        // Most human-readable first: an Asset's getFilename() is its content hash.
        foreach (['getTitle', 'getName', 'getPublicDisplayName', 'getUsername', 'getOriginalName', 'getLabel', 'getSlug', 'getFilename'] as $getter) {
            if (!method_exists($entity, $getter)) {
                continue;
            }

            try {
                $value = $entity->{$getter}();
            } catch (\Throwable) {
                continue;
            }

            if (\is_string($value) && trim($value) !== '') {
                return trim($value);
            }
        }

        return $resource.' #'.implode('-', array_map(static fn (mixed $v): string => (string) $v, array_values($ids)));
    }

    private function currentUserId(): ?int
    {
        $user = $this->tokenStorage->getToken()?->getUser();

        return $user instanceof User ? $user->getId() : null;
    }
}
