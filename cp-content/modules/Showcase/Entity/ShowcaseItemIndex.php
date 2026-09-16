<?php

declare(strict_types=1);

namespace Modules\Showcase\Entity;

use Doctrine\ORM\Mapping as ORM;
use Modules\Showcase\Repository\ShowcaseItemIndexRepository;

/**
 * Manifesto Law 6.3 flat index, the showcase's own copy of the pattern core uses
 * for nodes. One row per (item, field name); exactly one value* column is filled,
 * chosen by the field type's indexKind().
 *
 * Without this table, "cars under 500000 TL with automatic transmission" would be
 * a JSON scan over every row. With it, the same filter is an indexed join, and it
 * keeps working when the site owner invents a field the module has never heard of.
 */
#[ORM\Entity(repositoryClass: ShowcaseItemIndexRepository::class)]
#[ORM\Table(name: 'cp_showcase_item_index')]
#[ORM\UniqueConstraint(name: 'uniq_showcase_index_item_field', columns: ['item_id', 'field_name'])]
#[ORM\Index(columns: ['field_name', 'value_string'], name: 'idx_showcase_index_string')]
#[ORM\Index(columns: ['field_name', 'value_int'], name: 'idx_showcase_index_int')]
#[ORM\Index(columns: ['field_name', 'value_decimal'], name: 'idx_showcase_index_decimal')]
#[ORM\Index(columns: ['field_name', 'value_datetime'], name: 'idx_showcase_index_datetime')]
class ShowcaseItemIndex
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: ShowcaseItem::class)]
    #[ORM\JoinColumn(name: 'item_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private ShowcaseItem $item;

    #[ORM\Column(name: 'field_name', type: 'string', length: 100)]
    private string $fieldName;

    #[ORM\Column(name: 'value_string', type: 'string', length: 255, nullable: true)]
    private ?string $valueString = null;

    #[ORM\Column(name: 'value_int', type: 'integer', nullable: true)]
    private ?int $valueInt = null;

    #[ORM\Column(name: 'value_decimal', type: 'decimal', precision: 14, scale: 2, nullable: true)]
    private ?string $valueDecimal = null;

    #[ORM\Column(name: 'value_datetime', type: 'datetime', nullable: true)]
    private ?\DateTime $valueDatetime = null;

    public function __construct(ShowcaseItem $item, string $fieldName)
    {
        $this->item = $item;
        $this->fieldName = mb_substr($fieldName, 0, 100);
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getItem(): ShowcaseItem
    {
        return $this->item;
    }

    public function getFieldName(): string
    {
        return $this->fieldName;
    }

    public function getValueString(): ?string
    {
        return $this->valueString;
    }

    public function getValueInt(): ?int
    {
        return $this->valueInt;
    }

    public function getValueDecimal(): ?string
    {
        return $this->valueDecimal;
    }

    public function getValueDatetime(): ?\DateTime
    {
        return $this->valueDatetime;
    }

    /**
     * Writes exactly one typed column and clears the rest, so a field that
     * changes type cannot leave a stale value behind in the old column.
     */
    public function assign(string $kind, string|int|float|\DateTimeInterface|null $value): static
    {
        $this->valueString = null;
        $this->valueInt = null;
        $this->valueDecimal = null;
        $this->valueDatetime = null;

        if ($value === null) {
            return $this;
        }

        switch ($kind) {
            case 'int':
                $this->valueInt = (int) $value;
                break;
            case 'decimal':
                $this->valueDecimal = number_format((float) $value, 2, '.', '');
                break;
            case 'datetime':
                $this->valueDatetime = $value instanceof \DateTimeInterface
                    ? \DateTime::createFromInterface($value)
                    : null;
                break;
            default:
                $this->valueString = mb_substr((string) $value, 0, 255);
        }

        return $this;
    }
}
