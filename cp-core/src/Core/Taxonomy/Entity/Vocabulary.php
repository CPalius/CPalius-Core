<?php

declare(strict_types=1);

namespace App\Core\Taxonomy\Entity;

use App\Core\Taxonomy\Repository\VocabularyRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * A named set of terms (Drupal-style vocabulary). The vocabulary is structure —
 * exported to config/sync/taxonomy.{machine_name}.yaml — while its terms are
 * content and live only in the database.
 *
 * The machine_name doubles as the Field API bundle for its terms: define fields
 * for bundle "tags" from /aacp/fields and every term in that vocabulary gets them.
 */
#[ORM\Entity(repositoryClass: VocabularyRepository::class)]
#[ORM\Table(name: 'cp_vocabularies')]
#[ORM\UniqueConstraint(name: 'uniq_vocabulary_machine_name', columns: ['machine_name'])]
class Vocabulary
{
    public const MACHINE_NAME_PATTERN = '/^[a-z][a-z0-9_]{0,62}$/';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(name: 'machine_name', type: 'string', length: 64)]
    private string $machineName;

    #[ORM\Column(type: 'string', length: 191)]
    private string $label;

    #[ORM\Column(type: 'string', length: 500, nullable: true)]
    private ?string $description = null;

    /**
     * true → terms may nest under a parent term; false → flat tag list.
     */
    #[ORM\Column(type: 'boolean')]
    private bool $hierarchical = true;

    #[ORM\Column(type: 'integer')]
    private int $weight = 0;

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $updatedAt;

    public function __construct(string $machineName, string $label)
    {
        $this->machineName = $machineName;
        $this->label = $label;
        $this->createdAt = new \DateTimeImmutable();
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

        return $this->touch();
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): static
    {
        $description = $description !== null ? trim($description) : '';
        $this->description = $description !== '' ? mb_substr($description, 0, 500) : null;

        return $this->touch();
    }

    public function isHierarchical(): bool
    {
        return $this->hierarchical;
    }

    public function setHierarchical(bool $hierarchical): static
    {
        $this->hierarchical = $hierarchical;

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
            'machine_name' => $this->machineName,
            'label' => $this->label,
            'description' => $this->description,
            'hierarchical' => $this->hierarchical,
            'weight' => $this->weight,
        ];
    }

    private function touch(): static
    {
        $this->updatedAt = new \DateTimeImmutable();

        return $this;
    }
}
