<?php

declare(strict_types=1);

namespace Modules\Showcase\Service;

use App\Core\Field\Entity\FieldDefinition;
use App\Core\Field\FieldTypeRegistry;
use App\Core\Field\Type\SelectFieldType;
use App\Core\Field\FieldContext;
use Modules\Showcase\Entity\ShowcaseItem;
use Modules\Showcase\Entity\ShowcaseType;
use Modules\Showcase\Query\ShowcaseFilter;
use Modules\Showcase\Repository\ShowcaseItemRepository;
use Symfony\Component\HttpFoundation\Request;

/**
 * Turns a query string into a ShowcaseFilter, and describes which filters a type
 * actually offers.
 *
 * This is where "multi-purpose" stops being a promise and becomes behaviour: the
 * facets on a vehicle listing (year range, transmission, mileage) and on a SaaS
 * listing (licence, tech stack) are both generated from that type's queryable
 * FieldDefinitions. Neither is hard-coded anywhere in this module.
 *
 * Security note: only fields the type actually declares as queryable can reach
 * the query, and the operator for each is chosen from an allowlist by field kind.
 * A request parameter never becomes part of a DQL fragment.
 */
final class ShowcaseListingFilterBuilder
{
    /** Prefix for custom-field query parameters: ?f_transmission=automatic */
    private const FIELD_PARAM_PREFIX = 'f_';

    public function __construct(
        private readonly ShowcaseFieldIndexer $indexer,
        private readonly FieldTypeRegistry $fieldTypes,
        private readonly ShowcaseItemRepository $items,
    ) {
    }

    /**
     * @param list<string> $statuses
     */
    public function fromRequest(
        Request $request,
        string $locale,
        ?ShowcaseType $type,
        array $statuses = [ShowcaseItem::STATUS_PUBLISHED],
        ?int $ownerId = null,
        bool $withPrice = true,
    ): ShowcaseFilter {
        $query = $request->query;
        $sort = ShowcaseFilter::normalizeSort($query->get('sort'));

        // A price bound that arrives for a listing without prices is dropped
        // rather than applied: the form does not offer it, so it can only have
        // come from a hand-edited URL, and honouring it would empty the page.
        if (!$withPrice && \in_array($sort, [ShowcaseFilter::SORT_PRICE_ASC, ShowcaseFilter::SORT_PRICE_DESC], true)) {
            $sort = ShowcaseFilter::SORT_RECENT;
        }

        return new ShowcaseFilter(
            locale: $locale,
            type: $type,
            statuses: $statuses,
            termId: $this->positiveIntOrNull($query->get('category')),
            search: $this->searchTerm($query->get('q')),
            minPrice: $withPrice ? $this->floatOrNull($query->get('min_price')) : null,
            maxPrice: $withPrice ? $this->floatOrNull($query->get('max_price')) : null,
            ownerId: $ownerId,
            featuredOnly: $query->getBoolean('featured'),
            fieldCriteria: $type instanceof ShowcaseType ? $this->fieldCriteria($request, $type) : [],
            sort: $sort,
        );
    }

    /**
     * The facet definitions a template renders as filter inputs.
     *
     * @return list<array{
     *     name: string, label: string, kind: string, widget: string,
     *     param: string, choices: array<string, string>, value: string, min: string, max: string
     * }>
     */
    public function facetsFor(ShowcaseType $type, string $locale, Request $request): array
    {
        $facets = [];

        foreach ($this->indexer->queryableFields($type->fieldBundle()) as $name => $meta) {
            $definition = $meta['definition'];
            $kind = $meta['kind'];
            $widget = $this->widgetFor($definition, $kind);
            $param = self::FIELD_PARAM_PREFIX.$name;

            $facets[] = [
                'name' => $name,
                'label' => $definition->getLabel(),
                'kind' => $kind,
                'widget' => $widget,
                'param' => $param,
                'choices' => $widget === 'choice' ? $this->choicesFor($definition, $locale, $type, $name) : [],
                'value' => (string) $request->query->get($param, ''),
                'min' => (string) $request->query->get($param.'_min', ''),
                'max' => (string) $request->query->get($param.'_max', ''),
            ];
        }

        return $facets;
    }

    /**
     * Does a price filter make sense in this context?
     *
     * A type that does not use prices has no price column to filter on, so
     * offering "min / max" there is not just noise — it is a control that can
     * only ever return nothing. On the all-types listing the answer is "yes if
     * at least one enabled type prices its entries", because the filter would
     * still narrow those.
     *
     * @param list<ShowcaseType> $enabledTypes used when no single type is selected
     */
    public function supportsPrice(?ShowcaseType $type, array $enabledTypes): bool
    {
        if ($type instanceof ShowcaseType) {
            return $type->supports('price');
        }

        foreach ($enabledTypes as $candidate) {
            if ($candidate->supports('price')) {
                return true;
            }
        }

        return false;
    }

    /**
     * Sort options that mean something here. Ordering by price on a listing with
     * no prices produces an arbitrary order the visitor cannot explain, so those
     * two options are dropped rather than left to mislead.
     *
     * @return list<string>
     */
    public function sortsFor(bool $withPrice): array
    {
        if ($withPrice) {
            return ShowcaseFilter::SORTS;
        }

        return array_values(array_filter(
            ShowcaseFilter::SORTS,
            static fn (string $sort): bool => !\in_array(
                $sort,
                [ShowcaseFilter::SORT_PRICE_ASC, ShowcaseFilter::SORT_PRICE_DESC],
                true,
            ),
        ));
    }

    /**
     * Query parameters to preserve when building pagination and sort links, so a
     * visitor on page 3 of a filtered list keeps their filters.
     *
     * @return array<string, string>
     */
    public function preservedParams(Request $request): array
    {
        $keep = [];

        foreach ($request->query->all() as $key => $value) {
            if ($key === 'page' || !\is_string($value) || $value === '') {
                continue;
            }

            if (str_starts_with($key, self::FIELD_PARAM_PREFIX)
                || \in_array($key, ['q', 'category', 'sort', 'min_price', 'max_price', 'featured'], true)
            ) {
                $keep[$key] = $value;
            }
        }

        return $keep;
    }

    /**
     * @return list<array{field: string, kind: string, op: string, value: mixed}>
     */
    private function fieldCriteria(Request $request, ShowcaseType $type): array
    {
        $criteria = [];

        foreach ($this->indexer->queryableFields($type->fieldBundle()) as $name => $meta) {
            $kind = $meta['kind'];
            $param = self::FIELD_PARAM_PREFIX.$name;

            if (\in_array($kind, ['int', 'decimal'], true)) {
                $min = $this->floatOrNull($request->query->get($param.'_min'));
                $max = $this->floatOrNull($request->query->get($param.'_max'));

                if ($min !== null) {
                    $criteria[] = ['field' => $name, 'kind' => $kind, 'op' => '>=', 'value' => $min];
                }

                if ($max !== null) {
                    $criteria[] = ['field' => $name, 'kind' => $kind, 'op' => '<=', 'value' => $max];
                }

                continue;
            }

            $raw = $request->query->get($param);

            if (!\is_string($raw) || trim($raw) === '') {
                continue;
            }

            $criteria[] = [
                'field' => $name,
                'kind' => $kind,
                'op' => '=',
                'value' => mb_substr(trim($raw), 0, 255),
            ];
        }

        return $criteria;
    }

    private function widgetFor(FieldDefinition $definition, string $kind): string
    {
        if ($definition->getType() === 'select') {
            return 'choice';
        }

        if (\in_array($kind, ['int', 'decimal'], true)) {
            return 'range';
        }

        if ($definition->getType() === 'boolean') {
            return 'choice';
        }

        return 'text';
    }

    /**
     * Select fields use their configured choices; everything else offers the
     * distinct values already present in the index, so a filter never lists an
     * option that would return nothing.
     *
     * @return array<string, string> value => label
     */
    private function choicesFor(FieldDefinition $definition, string $locale, ShowcaseType $type, string $name): array
    {
        if ($definition->getType() === 'select') {
            $fieldType = $this->fieldTypes->get('select');

            if ($fieldType instanceof SelectFieldType) {
                return $fieldType->choicesFor(new FieldContext($definition, $locale));
            }
        }

        $values = $this->items->distinctIndexedValues($type, $name, $locale);
        $choices = [];

        foreach ($values as $value) {
            $choices[$value] = $value;
        }

        return $choices;
    }

    private function searchTerm(mixed $raw): ?string
    {
        if (!\is_string($raw)) {
            return null;
        }

        $term = trim(strip_tags($raw));

        // Single characters match almost everything and cost a full scan.
        return mb_strlen($term) >= 2 ? mb_substr($term, 0, 100) : null;
    }

    private function positiveIntOrNull(mixed $raw): ?int
    {
        return \is_string($raw) && ctype_digit($raw) && (int) $raw > 0 ? (int) $raw : null;
    }

    private function floatOrNull(mixed $raw): ?float
    {
        if (!\is_string($raw)) {
            return null;
        }

        $value = str_replace([' ', ','], ['', '.'], trim($raw));

        return is_numeric($value) ? (float) $value : null;
    }
}
