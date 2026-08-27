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
 * CPalius Manifesto 3.3 (High-Performance Querying) senkronizasyon motoru.
 *
 * Bir Node persist/update edildiğinde, QueryableFieldsRegistry'nin o
 * content type için tanımladığı alanları Node::data JSON'undan okuyup
 * NodeFieldIndex tablosuna "düz" (flat) kolonlar halinde yazar. Böylece
 * ağır JSON içi filtreleme yerine standart, indekslenebilir SQL WHERE
 * koşullarıyla sorgu atılabilir (bkz. NodeRepository::findByIndexedField).
 *
 * postPersist/postUpdate NEDEN kullanılıyor (prePersist/preUpdate DEĞİL):
 * NodeFieldIndex satırları Node->id'ye (join column) bağımlıdır; ID,
 * INSERT tamamlanana kadar (postPersist) atanmaz. Bu da demektir ki
 * UnitOfWork o Node için zaten commit edilmiştir — yeni persist/remove
 * çağrıları bu flush'a "yakalanamaz", bu yüzden idempotent senkronizasyon
 * kendi flush()'unu tetikler. computeChangeSet ile SADECE NodeFieldIndex
 * değişiklikleri hesaplanır; sonsuz postPersist/postUpdate döngüsüne
 * girilmez çünkü Node tekrar değiştirilmiyor.
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
                // Alan JSON'da artık yoksa/null ise eski endeksi temizle.
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

        // Registry'de artık tanımlı olmayan (ör. content type değişti)
        // eski endeks satırlarını temizle — idempotentlik için.
        foreach ($existingByField as $fieldName => $indexRow) {
            if (!\array_key_exists($fieldName, $fields)) {
                $em->remove($indexRow);
                $touched = true;
            }
        }

        if (!$touched) {
            return;
        }

        // Bu Node için zaten commit edilmiş olan UnitOfWork'ü tekrar
        // tetiklemeden, SADECE NodeFieldIndex değişikliklerini flush eder.
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
