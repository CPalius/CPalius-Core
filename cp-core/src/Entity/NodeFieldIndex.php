<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\NodeFieldIndexRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Manifesto 3.3 flat index for queryable Node::data fields (indexed SQL columns).
 * One row per node+fieldName; only one value* column is populated per type.
 */
#[ORM\Entity(repositoryClass: NodeFieldIndexRepository::class)]
#[ORM\Table(name: 'node_field_index')]
#[ORM\Index(columns: ['field_name', 'value_string'], name: 'idx_nfi_field_string')]
#[ORM\Index(columns: ['field_name', 'value_int'], name: 'idx_nfi_field_int')]
#[ORM\Index(columns: ['field_name', 'value_decimal'], name: 'idx_nfi_field_decimal')]
#[ORM\Index(columns: ['field_name', 'value_datetime'], name: 'idx_nfi_field_datetime')]
#[ORM\UniqueConstraint(name: 'uniq_node_field_index', columns: ['node_id', 'field_name'])]
class NodeFieldIndex
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Node::class)]
    #[ORM\JoinColumn(name: 'node_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private Node $node;

    /**
     * Dynamic field name matching a key in Node::data JSON.
     */
    #[ORM\Column(name: 'field_name', type: 'string', length: 100)]
    private string $fieldName;

    #[ORM\Column(name: 'value_string', type: 'string', length: 255, nullable: true)]
    private ?string $valueString = null;

    #[ORM\Column(name: 'value_int', type: 'integer', nullable: true)]
    private ?int $valueInt = null;

    #[ORM\Column(name: 'value_decimal', type: 'decimal', precision: 10, scale: 2, nullable: true)]
    private ?string $valueDecimal = null;

    #[ORM\Column(name: 'value_datetime', type: 'datetime', nullable: true)]
    private ?\DateTime $valueDatetime = null;

    public function __construct(Node $node, string $fieldName)
    {
        $this->node = $node;
        $this->fieldName = $fieldName;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getNode(): Node
    {
        return $this->node;
    }

    public function getFieldName(): string
    {
        return $this->fieldName;
    }

    public function getValueString(): ?string
    {
        return $this->valueString;
    }

    public function setValueString(?string $valueString): static
    {
        $this->valueString = $valueString;

        return $this;
    }

    public function getValueInt(): ?int
    {
        return $this->valueInt;
    }

    public function setValueInt(?int $valueInt): static
    {
        $this->valueInt = $valueInt;

        return $this;
    }

    /**
     * Doctrine decimal maps to string in PHP to avoid float precision loss.
     */
    public function getValueDecimal(): ?string
    {
        return $this->valueDecimal;
    }

    public function setValueDecimal(string|float|null $valueDecimal): static
    {
        $this->valueDecimal = $valueDecimal !== null ? (string) $valueDecimal : null;

        return $this;
    }

    public function getValueDatetime(): ?\DateTime
    {
        return $this->valueDatetime;
    }

    public function setValueDatetime(?\DateTime $valueDatetime): static
    {
        $this->valueDatetime = $valueDatetime;

        return $this;
    }
}
