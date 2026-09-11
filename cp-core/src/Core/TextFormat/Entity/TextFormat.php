<?php

declare(strict_types=1);

namespace App\Core\TextFormat\Entity;

use App\Core\TextFormat\Repository\TextFormatRepository;
use App\Core\TextFormat\TextFormatRegistry;
use Doctrine\ORM\Mapping as ORM;

/**
 * Operator override of a named text format (YAML is the seed). Deleting the
 * row reverts to YAML — never touches stored content (the format id on a
 * rich_text value still resolves via the catalog fallback).
 */
#[ORM\Entity(repositoryClass: TextFormatRepository::class)]
#[ORM\Table(name: 'cp_text_formats')]
#[ORM\UniqueConstraint(name: 'uniq_text_format_machine_name', columns: ['machine_name'])]
class TextFormat
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(name: 'machine_name', type: 'string', length: 32)]
    private string $machineName;

    #[ORM\Column(type: 'string', length: 191)]
    private string $label;

    #[ORM\Column(type: 'string', length: 500)]
    private string $description = '';

    #[ORM\Column(type: 'boolean')]
    private bool $wysiwyg = false;

    /** @var list<array{id: string, enabled: bool, weight: int, settings: array<string, mixed>}> */
    #[ORM\Column(type: 'json')]
    private array $filters = [];

    #[ORM\Column(type: 'boolean')]
    private bool $locked = true;

    #[ORM\Column(name: 'updated_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $updatedAt;

    public function __construct(string $machineName, string $label)
    {
        $this->machineName = $machineName;
        $this->label = $label;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getMachineName(): string
    {
        return $this->machineName;
    }

    public function getLabel(): string
    {
        return $this->label;
    }

    public function setLabel(string $label): static
    {
        $this->label = $label;
        $this->touch();

        return $this;
    }

    public function getDescription(): string
    {
        return $this->description;
    }

    public function setDescription(string $description): static
    {
        $this->description = $description;
        $this->touch();

        return $this;
    }

    public function isWysiwyg(): bool
    {
        return $this->wysiwyg;
    }

    public function setWysiwyg(bool $wysiwyg): static
    {
        $this->wysiwyg = $wysiwyg;
        $this->touch();

        return $this;
    }

    /**
     * @return list<array{id: string, enabled: bool, weight: int, settings: array<string, mixed>}>
     */
    public function getFilters(): array
    {
        return $this->filters;
    }

    /**
     * @param list<array{id: string, enabled: bool, weight: int, settings: array<string, mixed>}> $filters
     */
    public function setFilters(array $filters): static
    {
        $this->filters = $filters;
        $this->touch();

        return $this;
    }

    public function isLocked(): bool
    {
        return $this->locked;
    }

    public function setLocked(bool $locked): static
    {
        $this->locked = $locked;

        return $this;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function capability(): string
    {
        return 'text_format.'.$this->machineName.'.use';
    }

    public static function isValidMachineName(string $name): bool
    {
        return preg_match(TextFormatRegistry::ID_PATTERN, $name) === 1;
    }

    private function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }
}
