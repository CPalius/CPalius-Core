<?php

declare(strict_types=1);

namespace Modules\Showcase\Service;

use App\Core\Field\FieldDefinitionSeeder;
use App\Core\Localization\LocaleProvider;
use Modules\Showcase\Entity\ShowcaseType;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * One-click starting points for a new showcase type.
 *
 * A blank type with no fields is correct but unhelpful — "add your own fields" is
 * a lot to ask of someone who just wants to list second-hand cars. Each preset
 * creates the type AND seeds a field schema that suits it, which the operator
 * then edits, extends or deletes like any other field.
 *
 * Presets are examples, not a fixed catalogue: nothing in the module depends on
 * these names, and a type built entirely by hand behaves identically.
 *
 * Field labels are resolved through the translator at seed time rather than
 * stored as translation keys, because FieldDefinition holds ONE label that the
 * operator edits from the panel afterwards — a key would show up verbatim on the
 * public page the moment someone renamed it.
 */
final class ShowcasePresetLibrary
{
    /**
     * @var array<string, array{
     *     icon: string,
     *     features: array<string, bool>,
     *     fields: list<array{name: string, type: string, required?: bool, queryable?: bool, group?: string, settings?: array<string, mixed>, weight?: int}>
     * }>
     */
    private const PRESETS = [
        'vehicle' => [
            'icon' => 'heroicons:truck',
            'features' => ['price' => true, 'gallery' => true, 'location' => true, 'contact' => true, 'demo_url' => false, 'external_url' => false, 'reviews' => false],
            'fields' => [
                ['name' => 'brand', 'type' => 'text', 'queryable' => true, 'group' => 'spec', 'weight' => 10],
                ['name' => 'model', 'type' => 'text', 'queryable' => true, 'group' => 'spec', 'weight' => 20],
                ['name' => 'model_year', 'type' => 'integer', 'queryable' => true, 'group' => 'spec', 'weight' => 30],
                ['name' => 'mileage_km', 'type' => 'integer', 'queryable' => true, 'group' => 'spec', 'weight' => 40],
                ['name' => 'fuel', 'type' => 'select', 'queryable' => true, 'group' => 'spec', 'weight' => 50, 'settings' => ['choices' => "petrol|Benzin\ndiesel|Dizel\nlpg|LPG\nhybrid|Hibrit\nelectric|Elektrik"]],
                ['name' => 'transmission', 'type' => 'select', 'queryable' => true, 'group' => 'spec', 'weight' => 60, 'settings' => ['choices' => "manual|Manuel\nautomatic|Otomatik\nsemi|Yarı otomatik"]],
                ['name' => 'body_condition', 'type' => 'select', 'queryable' => true, 'group' => 'spec', 'weight' => 70, 'settings' => ['choices' => "new|Sıfır\nused|İkinci el\ndamaged|Hasarlı"]],
                ['name' => 'color', 'type' => 'text', 'group' => 'spec', 'weight' => 80],
            ],
        ],
        'website' => [
            'icon' => 'heroicons:globe-alt',
            'features' => ['price' => true, 'gallery' => true, 'external_url' => true, 'demo_url' => true, 'contact' => true, 'location' => false, 'reviews' => true],
            'fields' => [
                ['name' => 'domain', 'type' => 'text', 'required' => true, 'queryable' => true, 'group' => 'metrics', 'weight' => 10],
                ['name' => 'monthly_visitors', 'type' => 'integer', 'queryable' => true, 'group' => 'metrics', 'weight' => 20],
                ['name' => 'monthly_revenue', 'type' => 'decimal', 'queryable' => true, 'group' => 'metrics', 'weight' => 30],
                ['name' => 'monetization', 'type' => 'select', 'queryable' => true, 'group' => 'metrics', 'weight' => 40, 'settings' => ['choices' => "ads|Reklam\naffiliate|Affiliate\nsubscription|Abonelik\necommerce|E-ticaret\nnone|Yok"]],
                ['name' => 'site_age_years', 'type' => 'integer', 'queryable' => true, 'group' => 'metrics', 'weight' => 50],
                ['name' => 'tech_stack', 'type' => 'text', 'group' => 'tech', 'weight' => 60],
                ['name' => 'analytics_url', 'type' => 'url', 'group' => 'tech', 'weight' => 70],
            ],
        ],
        'software' => [
            'icon' => 'heroicons:code-bracket',
            'features' => ['price' => true, 'gallery' => true, 'external_url' => true, 'demo_url' => true, 'contact' => true, 'location' => false, 'reviews' => true],
            'fields' => [
                ['name' => 'version', 'type' => 'text', 'queryable' => true, 'group' => 'release', 'weight' => 10],
                ['name' => 'license', 'type' => 'select', 'queryable' => true, 'group' => 'release', 'weight' => 20, 'settings' => ['choices' => "mit|MIT\ngpl|GPL\napache|Apache 2.0\nproprietary|Ticari\nfreemium|Freemium"]],
                ['name' => 'platform', 'type' => 'select', 'queryable' => true, 'group' => 'release', 'weight' => 30, 'settings' => ['choices' => "web|Web\nwindows|Windows\nmacos|macOS\nlinux|Linux\nandroid|Android\nios|iOS"]],
                ['name' => 'tech_language', 'type' => 'text', 'queryable' => true, 'group' => 'release', 'weight' => 40],
                ['name' => 'repository_url', 'type' => 'url', 'group' => 'links', 'weight' => 50],
                ['name' => 'docs_url', 'type' => 'url', 'group' => 'links', 'weight' => 60],
                ['name' => 'released_at', 'type' => 'date', 'queryable' => true, 'group' => 'release', 'weight' => 70],
            ],
        ],
        'service' => [
            'icon' => 'heroicons:briefcase',
            'features' => ['price' => true, 'gallery' => true, 'external_url' => true, 'contact' => true, 'location' => true, 'demo_url' => false, 'reviews' => true],
            'fields' => [
                ['name' => 'delivery_days', 'type' => 'integer', 'queryable' => true, 'group' => 'terms', 'weight' => 10],
                ['name' => 'revision_count', 'type' => 'integer', 'group' => 'terms', 'weight' => 20],
                ['name' => 'experience_years', 'type' => 'integer', 'queryable' => true, 'group' => 'terms', 'weight' => 30],
                ['name' => 'remote_available', 'type' => 'boolean', 'queryable' => true, 'group' => 'terms', 'weight' => 40],
            ],
        ],
        'property' => [
            'icon' => 'heroicons:home-modern',
            'features' => ['price' => true, 'gallery' => true, 'location' => true, 'contact' => true, 'external_url' => false, 'demo_url' => false, 'reviews' => false],
            'fields' => [
                ['name' => 'rooms', 'type' => 'select', 'queryable' => true, 'group' => 'spec', 'weight' => 10, 'settings' => ['choices' => "1+0|1+0\n1+1|1+1\n2+1|2+1\n3+1|3+1\n4+1|4+1\n5+|5+"]],
                ['name' => 'area_m2', 'type' => 'integer', 'queryable' => true, 'group' => 'spec', 'weight' => 20],
                ['name' => 'floor_no', 'type' => 'integer', 'group' => 'spec', 'weight' => 30],
                ['name' => 'building_age', 'type' => 'integer', 'queryable' => true, 'group' => 'spec', 'weight' => 40],
                ['name' => 'heating', 'type' => 'select', 'queryable' => true, 'group' => 'spec', 'weight' => 50, 'settings' => ['choices' => "natural_gas|Doğalgaz\ncentral|Merkezi\nelectric|Elektrikli\nnone|Yok"]],
            ],
        ],
    ];

    public function __construct(
        private readonly ShowcaseTypeManager $typeManager,
        private readonly FieldDefinitionSeeder $fieldSeeder,
        private readonly TranslatorInterface $translator,
        private readonly LocaleProvider $localeProvider,
    ) {
    }

    /**
     * @return list<array{id: string, label: string, description: string, icon: string, fieldCount: int}>
     */
    public function catalogue(): array
    {
        $out = [];

        foreach (self::PRESETS as $id => $preset) {
            $out[] = [
                'id' => $id,
                'label' => $this->translator->trans('showcase.preset.'.$id.'.label'),
                'description' => $this->translator->trans('showcase.preset.'.$id.'.description'),
                'icon' => $preset['icon'],
                'fieldCount' => \count($preset['fields']),
            ];
        }

        return $out;
    }

    public function has(string $presetId): bool
    {
        return isset(self::PRESETS[$presetId]);
    }

    /**
     * Creates the type and seeds its fields. The machine name is the preset id
     * unless it is taken, in which case the caller is told rather than silently
     * pouring fields into someone else's type.
     *
     * @return array{type: ?ShowcaseType, error: ?string, fieldsCreated: int}
     */
    public function apply(string $presetId): array
    {
        if (!isset(self::PRESETS[$presetId])) {
            return ['type' => null, 'error' => 'showcase.types.error.unknown_preset', 'fieldsCreated' => 0];
        }

        $preset = self::PRESETS[$presetId];
        $labels = [];

        foreach ($this->localeProvider->getCodes() as $locale) {
            $labels[$locale] = [
                'label' => $this->translator->trans('showcase.preset.'.$presetId.'.label', [], null, $locale),
                'description' => $this->translator->trans('showcase.preset.'.$presetId.'.description', [], null, $locale),
            ];
        }

        $result = $this->typeManager->create($presetId, $labels, $preset['features'], $preset['icon'], true);

        if ($result['type'] === null) {
            return ['type' => null, 'error' => $result['error'], 'fieldsCreated' => 0];
        }

        $type = $result['type'];

        return ['type' => $type, 'error' => null, 'fieldsCreated' => $this->seedFields($type, $presetId)];
    }

    /**
     * Adds a preset's field schema to an EXISTING type.
     *
     * Separate from apply() so a type that already exists — created by hand, or
     * left with a partial schema by an interrupted run — can be topped up without
     * being recreated. FieldDefinitionSeeder only ever creates what is missing, so
     * this never overwrites a field an editor has since tuned.
     *
     * @return int number of fields created
     */
    public function seedFields(ShowcaseType $type, string $presetId): int
    {
        if (!isset(self::PRESETS[$presetId])) {
            return 0;
        }

        $bundle = $type->fieldBundle();
        $defaultLocale = $this->localeProvider->getDefaultCode();
        $specs = [];

        foreach (self::PRESETS[$presetId]['fields'] as $field) {
            $specs[] = [
                'bundle' => $bundle,
                'name' => $field['name'],
                'type' => $field['type'],
                'label' => $this->translator->trans(
                    'showcase.preset.field.'.$field['name'],
                    [],
                    null,
                    $defaultLocale,
                ),
                'required' => $field['required'] ?? false,
                'queryable' => $field['queryable'] ?? false,
                'group' => $field['group'] ?? null,
                'weight' => $field['weight'] ?? 0,
                'settings' => $field['settings'] ?? [],
                // Specification values (a model year, a licence code) are the same
                // in every language; the operator flips this per field when it is
                // not, e.g. a free-text description field.
                'translatable' => false,
            ];
        }

        return $this->fieldSeeder->ensure($specs);
    }

    /**
     * Field names a preset defines, so a caller can generate values for them
     * without duplicating the schema.
     *
     * @return list<string>
     */
    public function fieldNames(string $presetId): array
    {
        if (!isset(self::PRESETS[$presetId])) {
            return [];
        }

        return array_map(
            static fn (array $field): string => $field['name'],
            self::PRESETS[$presetId]['fields'],
        );
    }
}
