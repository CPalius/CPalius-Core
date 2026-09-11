<?php

declare(strict_types=1);

namespace App\Core\Field\Entity;

use App\Core\Field\Repository\FieldDefinitionRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * A field attached to a content bundle (Node::type value). The value itself lives
 * in Node::data[$name] — this row only describes the field. Editable at runtime
 * from /aacp/fields and exportable to config/sync/field.{bundle}.yaml (Faz C).
 */
#[ORM\Entity(repositoryClass: FieldDefinitionRepository::class)]
#[ORM\Table(name: 'cp_field_definitions')]
#[ORM\UniqueConstraint(name: 'uniq_field_bundle_name', columns: ['bundle', 'name'])]
#[ORM\Index(columns: ['bundle', 'weight'], name: 'idx_field_bundle_weight')]
class FieldDefinition
{
    /** Cardinality sentinel: any number of values. */
    public const UNLIMITED = -1;

    public const NAME_PATTERN = '/^[a-z][a-z0-9_]{0,62}$/';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(type: 'string', length: 64)]
    private string $bundle;

    #[ORM\Column(type: 'string', length: 64)]
    private string $name;

    #[ORM\Column(type: 'string', length: 32)]
    private string $type;

    #[ORM\Column(type: 'string', length: 191)]
    private string $label;

    #[ORM\Column(type: 'string', length: 500, nullable: true)]
    private ?string $help = null;

    #[ORM\Column(type: 'boolean')]
    private bool $required = false;

    /** 1 = single value, -1 (UNLIMITED) = any number, N = up to N values. */
    #[ORM\Column(type: 'integer')]
    private int $cardinality = 1;

    #[ORM\Column(type: 'boolean')]
    private bool $translatable = true;

    #[ORM\Column(type: 'boolean')]
    private bool $queryable = false;

    #[ORM\Column(name: 'field_group', type: 'string', length: 64, nullable: true)]
    private ?string $fieldGroup = null;

    #[ORM\Column(type: 'integer')]
    private int $weight = 0;

    /** @var array<string, mixed> Type-specific settings, validated against the type's settingsSchema(). */
    #[ORM\Column(type: 'json')]
    private array $settings = [];

    #[ORM\Column(name: 'view_capability', type: 'string', length: 100, nullable: true)]
    private ?string $viewCapability = null;

    #[ORM\Column(name: 'edit_capability', type: 'string', length: 100, nullable: true)]
    private ?string $editCapability = null;

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $updatedAt;

    public function __construct(string $bundle, string $name, string $type, string $label)
    {
        $this->bundle = $bundle;
        $this->name = $name;
        $this->type = $type;
        $this->label = $label;
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getBundle(): string
    {
        return $this->bundle;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getType(): string
    {
        return $this->type;
    }

    /**
     * Type is immutable once a bundle holds data — enforced by the admin controller,
     * kept assignable here for the seeder/import path.
     */
    public function setType(string $type): static
    {
        $this->type = $type;

        return $this->touch();
    }

    public function getLabel(): string
    {
        return $this->label;
    }

    public function setLabel(string $label): static
    {
        $this->label = $label;

        return $this->touch();
    }

    public function getHelp(): ?string
    {
        return $this->help;
    }

    public function setHelp(?string $help): static
    {
        $this->help = $help !== null && trim($help) !== '' ? mb_substr(trim($help), 0, 500) : null;

        return $this->touch();
    }

    public function isRequired(): bool
    {
        return $this->required;
    }

    public function setRequired(bool $required): static
    {
        $this->required = $required;

        return $this->touch();
    }

    public function getCardinality(): int
    {
        return $this->cardinality;
    }

    public function setCardinality(int $cardinality): static
    {
        $this->cardinality = $cardinality === self::UNLIMITED ? self::UNLIMITED : max(1, $cardinality);

        return $this->touch();
    }

    public function isMultiValue(): bool
    {
        return $this->cardinality === self::UNLIMITED || $this->cardinality > 1;
    }

    public function isTranslatable(): bool
    {
        return $this->translatable;
    }

    public function setTranslatable(bool $translatable): static
    {
        $this->translatable = $translatable;

        return $this->touch();
    }

    public function isQueryable(): bool
    {
        return $this->queryable;
    }

    public function setQueryable(bool $queryable): static
    {
        $this->queryable = $queryable;

        return $this->touch();
    }

    public function getFieldGroup(): ?string
    {
        return $this->fieldGroup;
    }

    public function setFieldGroup(?string $fieldGroup): static
    {
        $fieldGroup = $fieldGroup !== null ? trim($fieldGroup) : '';
        $this->fieldGroup = $fieldGroup !== '' ? mb_substr($fieldGroup, 0, 64) : null;

        return $this->touch();
    }

    public function getWeight(): int
    {
        return $this->weight;
    }

    public function setWeight(int $weight): static
    {
        $this->weight = $weight;

        return $this->touch();
    }

    /**
     * @return array<string, mixed>
     */
    public function getSettings(): array
    {
        return $this->settings;
    }

    public function getSetting(string $key, mixed $default = null): mixed
    {
        return $this->settings[$key] ?? $default;
    }

    /**
     * @param array<string, mixed> $settings
     */
    public function setSettings(array $settings): static
    {
        $this->settings = $settings;

        return $this->touch();
    }

    public function getViewCapability(): ?string
    {
        return $this->viewCapability;
    }

    public function setViewCapability(?string $capability): static
    {
        $this->viewCapability = $this->normalizeCapability($capability);

        return $this->touch();
    }

    public function getEditCapability(): ?string
    {
        return $this->editCapability;
    }

    public function setEditCapability(?string $capability): static
    {
        $this->editCapability = $this->normalizeCapability($capability);

        return $this->touch();
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'bundle' => $this->bundle,
            'name' => $this->name,
            'type' => $this->type,
            'label' => $this->label,
            'help' => $this->help,
            'required' => $this->required,
            'cardinality' => $this->cardinality,
            'translatable' => $this->translatable,
            'queryable' => $this->queryable,
            'group' => $this->fieldGroup,
            'weight' => $this->weight,
            'settings' => $this->settings,
            'view_capability' => $this->viewCapability,
            'edit_capability' => $this->editCapability,
        ];
    }

    /**
     * Builds a DETACHED definition from cached/exported data. Not for persistence —
     * the AACP editor loads managed entities from the repository directly.
     *
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $definition = new self(
            (string) ($data['bundle'] ?? ''),
            (string) ($data['name'] ?? ''),
            (string) ($data['type'] ?? 'text'),
            (string) ($data['label'] ?? ''),
        );
        $definition->id = isset($data['id']) && is_numeric($data['id']) ? (int) $data['id'] : null;
        $definition->help = \is_string($data['help'] ?? null) && $data['help'] !== '' ? $data['help'] : null;
        $definition->required = (bool) ($data['required'] ?? false);
        $definition->cardinality = (int) ($data['cardinality'] ?? 1);
        $definition->translatable = (bool) ($data['translatable'] ?? true);
        $definition->queryable = (bool) ($data['queryable'] ?? false);
        $definition->fieldGroup = \is_string($data['group'] ?? null) && $data['group'] !== '' ? $data['group'] : null;
        $definition->weight = (int) ($data['weight'] ?? 0);
        $definition->settings = \is_array($data['settings'] ?? null) ? $data['settings'] : [];
        $definition->viewCapability = $definition->normalizeCapability($data['view_capability'] ?? null);
        $definition->editCapability = $definition->normalizeCapability($data['edit_capability'] ?? null);

        return $definition;
    }

    private function normalizeCapability(mixed $capability): ?string
    {
        if (!\is_string($capability) || $capability === '') {
            return null;
        }

        $capability = strtolower(trim($capability));

        return preg_match('/^[a-z][a-z0-9_.]{0,99}$/', $capability) === 1 ? $capability : null;
    }

    private function touch(): static
    {
        $this->updatedAt = new \DateTimeImmutable();

        return $this;
    }
}
