<?php

declare(strict_types=1);

namespace App\Core\Display\Entity;

use App\Core\Display\Repository\EntityDisplayRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Per-(bundle, view mode, field) display override: visibility, order and
 * label placement. Absence of a row means "use the field's own defaults" —
 * EntityDisplayRegistry fills that gap, so an unconfigured bundle renders
 * exactly as FieldRenderer already did before view modes existed.
 *
 * Formatter settings are NOT overridable per view mode in this version —
 * only visibility/weight/label vary; the field type's own formatter and
 * settings (FieldDefinition) render the value the same way everywhere.
 */
#[ORM\Entity(repositoryClass: EntityDisplayRepository::class)]
#[ORM\Table(name: 'cp_entity_displays')]
#[ORM\Index(columns: ['bundle', 'view_mode'], name: 'idx_display_bundle_viewmode')]
#[ORM\UniqueConstraint(name: 'uniq_display_bundle_viewmode_field', columns: ['bundle', 'view_mode', 'field_name'])]
class EntityDisplay
{
    public const LABEL_ABOVE = 'above';
    public const LABEL_INLINE = 'inline';
    public const LABEL_HIDDEN = 'hidden';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(type: 'string', length: 64)]
    private string $bundle;

    #[ORM\Column(name: 'view_mode', type: 'string', length: 32)]
    private string $viewMode;

    #[ORM\Column(name: 'field_name', type: 'string', length: 64)]
    private string $fieldName;

    #[ORM\Column(type: 'boolean')]
    private bool $visible = true;

    #[ORM\Column(type: 'integer')]
    private int $weight = 0;

    #[ORM\Column(name: 'label_display', type: 'string', length: 10)]
    private string $labelDisplay = self::LABEL_ABOVE;

    public function __construct(string $bundle, string $viewMode, string $fieldName)
    {
        $this->bundle = $bundle;
        $this->viewMode = $viewMode;
        $this->fieldName = $fieldName;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getBundle(): string
    {
        return $this->bundle;
    }

    public function getViewMode(): string
    {
        return $this->viewMode;
    }

    public function getFieldName(): string
    {
        return $this->fieldName;
    }

    public function isVisible(): bool
    {
        return $this->visible;
    }

    public function setVisible(bool $visible): static
    {
        $this->visible = $visible;

        return $this;
    }

    public function getWeight(): int
    {
        return $this->weight;
    }

    public function setWeight(int $weight): static
    {
        $this->weight = $weight;

        return $this;
    }

    public function getLabelDisplay(): string
    {
        return $this->labelDisplay;
    }

    public function setLabelDisplay(string $labelDisplay): static
    {
        $this->labelDisplay = \in_array($labelDisplay, [self::LABEL_ABOVE, self::LABEL_INLINE, self::LABEL_HIDDEN], true)
            ? $labelDisplay
            : self::LABEL_ABOVE;

        return $this;
    }

    /**
     * @return array{visible: bool, weight: int, label_display: string}
     */
    public function toArray(): array
    {
        return [
            'visible' => $this->visible,
            'weight' => $this->weight,
            'label_display' => $this->labelDisplay,
        ];
    }
}
