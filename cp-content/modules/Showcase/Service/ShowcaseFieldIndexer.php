<?php

declare(strict_types=1);

namespace Modules\Showcase\Service;

use App\Core\Field\Entity\FieldDefinition;
use App\Core\Field\FieldDefinitionRegistry;
use App\Core\Field\FieldTypeRegistry;
use Doctrine\ORM\EntityManagerInterface;
use Modules\Showcase\Entity\ShowcaseItem;
use Modules\Showcase\Entity\ShowcaseItemIndex;
use Modules\Showcase\Repository\ShowcaseItemIndexRepository;

/**
 * Keeps cp_showcase_item_index in step with an item's JSON field values
 * (Manifesto Law 6.3).
 *
 * Only single-value fields an editor marked "queryable" are flattened. Multi-value
 * fields are skipped: one row per (item, field) cannot represent a list, and
 * silently indexing the first value would make filters quietly wrong — worse than
 * not offering the filter at all.
 */
final class ShowcaseFieldIndexer
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly FieldDefinitionRegistry $definitions,
        private readonly FieldTypeRegistry $types,
        private readonly ShowcaseItemIndexRepository $indexRepository,
    ) {
    }

    /**
     * Rewrites every index row for one item. Called after the item's field values
     * are persisted; the caller flushes.
     */
    public function sync(ShowcaseItem $item): void
    {
        $existing = $this->indexRepository->findForItem($item);
        $data = $item->getFieldableData();
        $seen = [];

        foreach ($this->indexable($item->fieldableBundle()) as $definition) {
            $name = $definition->getName();
            $type = $this->types->get($definition->getType());
            $kind = $type->indexKind();

            if ($kind === null) {
                continue;
            }

            $value = $type->indexValue($data[$name] ?? null);

            if ($value === null || $value === '') {
                continue;
            }

            $row = $existing[$name] ?? new ShowcaseItemIndex($item, $name);
            $row->assign($kind, $value);

            if (!isset($existing[$name])) {
                $this->entityManager->persist($row);
            }

            $seen[$name] = true;
        }

        // Rows for fields that were cleared, deleted, or made non-queryable.
        foreach ($existing as $name => $row) {
            if (!isset($seen[$name])) {
                $this->entityManager->remove($row);
            }
        }
    }

    /**
     * Queryable single-value fields of a bundle, with their index kind. Used both
     * by the indexer and by the listing filter builder, so the filters offered and
     * the values indexed can never drift apart.
     *
     * @return array<string, array{definition: FieldDefinition, kind: string}>
     */
    public function queryableFields(string $bundle): array
    {
        $out = [];

        foreach ($this->indexable($bundle) as $definition) {
            $kind = $this->types->get($definition->getType())->indexKind();

            if ($kind !== null) {
                $out[$definition->getName()] = ['definition' => $definition, 'kind' => $kind];
            }
        }

        return $out;
    }

    /**
     * @return list<FieldDefinition>
     */
    private function indexable(string $bundle): array
    {
        $out = [];

        foreach ($this->definitions->getFieldsForBundle($bundle) as $definition) {
            if (!$definition->isQueryable() || $definition->isMultiValue()) {
                continue;
            }

            if (!$this->types->has($definition->getType())) {
                continue;
            }

            $out[] = $definition;
        }

        return $out;
    }
}
