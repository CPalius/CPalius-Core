<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\ForumTopicPrefixRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Konu ön eki — başlığın önünde gösterilen, admin tanımlı renkli etiket
 * (ör. "Çözüldü", "Duyuru", "Soru"). phpBB/Discourse tarzı "topic prefix"
 * kavramının CPalius karşılığı; Cotonti forums modülünde doğrudan yok.
 */
#[ORM\Entity(repositoryClass: ForumTopicPrefixRepository::class)]
#[ORM\Table(name: 'forum_topic_prefixes')]
class ForumTopicPrefix
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(type: 'string', length: 32)]
    private string $label;

    #[ORM\Column(type: 'string', length: 7)]
    private string $color = '#4AADE4';

    #[ORM\Column(name: 'sort_order', type: 'integer')]
    private int $sortOrder = 0;

    public function __construct(string $label, string $color = '#4AADE4')
    {
        $this->label = $label;
        $this->color = $color;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getLabel(): string
    {
        return $this->label;
    }

    public function setLabel(string $label): static
    {
        $this->label = $label;

        return $this;
    }

    public function getColor(): string
    {
        return $this->color;
    }

    public function setColor(string $color): static
    {
        $this->color = $color;

        return $this;
    }

    public function getSortOrder(): int
    {
        return $this->sortOrder;
    }

    public function setSortOrder(int $sortOrder): static
    {
        $this->sortOrder = $sortOrder;

        return $this;
    }
}
