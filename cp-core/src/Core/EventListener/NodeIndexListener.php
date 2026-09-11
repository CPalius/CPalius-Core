<?php

namespace App\Core\EventListener;

use App\Core\Content\QueryableFieldsRegistry;
use App\Entity\Node;
use App\Entity\NodeFieldIndex;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\PostPersistEventArgs;
use Doctrine\ORM\Event\PostUpdateEventArgs;
use Doctrine\ORM\Events;

/**
 * Syncs QueryableFieldsRegistry fields from Node::data JSON into flat NodeFieldIndex rows for indexed SQL queries.
 * Uses postPersist/postUpdate because NodeFieldIndex rows need the Node id assigned after insert.
 */
#[AsDoctrineListener(event: Events::postPersist)]
#[AsDoctrineListener(event: Events::postUpdate)]
final class NodeIndexListener
{
    public function __construct(
        private readonly QueryableFieldsRegistry $fieldsRegistry,
    ) {
    }

    public function postPersist(PostPersistEventArgs $args): void
    {
        $this->syncIfNode($args->getObject(), $args->getObjectManager());
    }

    public function postUpdate(PostUpdateEventArgs $args): void
    {
        $this->syncIfNode($args->getObject(), $args->getObjectManager());
    }

    private function syncIfNode(object $entity, EntityManagerInterface $em): void
    {
        if (!$entity instanceof Node) {
            return;
        }

        $fields = $this->fieldsRegistry->getFieldsForType($entity->getType());

        $repository = $em->getRepository(NodeFieldIndex::class);
        $existing = $repository->findBy(['node' => $entity]);

        /** @var array<string, NodeFieldIndex> $existingByField */
        $existingByField = [];
        foreach ($existing as $indexRow) {
            $existingByField[$indexRow->getFieldName()] = $indexRow;
        }

        $touched = false;

        foreach ($fields as $fieldName => $valueType) {
            $rawValue = $entity->getDataValue($fieldName);
            $indexRow = $existingByField[$fieldName] ?? null;

            if ($rawValue === null) {
                // Remove stale index row when the field is null or absent from JSON.
                if ($indexRow !== null) {
                    $em->remove($indexRow);
                    unset($existingByField[$fieldName]);
                    $touched = true;
                }

                continue;
            }

            if ($indexRow === null) {
                $indexRow = new NodeFieldIndex($entity, $fieldName);
                $em->persist($indexRow);
                $existingByField[$fieldName] = $indexRow;
            }

            $this->applyValue($indexRow, $valueType, $rawValue);
            $touched = true;
        }

        // Drop index rows for fields no longer defined for this content type.
        foreach ($existingByField as $fieldName => $indexRow) {
            if (!\array_key_exists($fieldName, $fields)) {
                $em->remove($indexRow);
                $touched = true;
            }
        }

        if (!$touched) {
            return;
        }

        // Flush only NodeFieldIndex changes without re-triggering the committed Node UnitOfWork.
        $em->flush();
    }

    private function applyValue(NodeFieldIndex $indexRow, string $valueType, mixed $rawValue): void
    {
        $indexRow->setValueString(null);
        $indexRow->setValueInt(null);
        $indexRow->setValueDecimal(null);
        $indexRow->setValueDatetime(null);

        match ($valueType) {
            QueryableFieldsRegistry::TYPE_STRING => $indexRow->setValueString((string) $rawValue),
            QueryableFieldsRegistry::TYPE_INT => $indexRow->setValueInt((int) $rawValue),
            QueryableFieldsRegistry::TYPE_DECIMAL => $indexRow->setValueDecimal((string) $rawValue),
            QueryableFieldsRegistry::TYPE_DATETIME => $indexRow->setValueDatetime(
                $rawValue instanceof \DateTimeInterface
                    ? \DateTime::createFromInterface($rawValue)
                    : new \DateTime((string) $rawValue)
            ),
            default => throw new \LogicException(sprintf('Bilinmeyen QueryableFieldsRegistry tipi: "%s"', $valueType)),
        };
    }
}
