<?php

declare(strict_types=1);

namespace Modules\Forum\Entity;

use Doctrine\ORM\Mapping as ORM;
use Modules\Forum\Repository\ForumCensorWordRepository;

#[ORM\Entity(repositoryClass: ForumCensorWordRepository::class)]
#[ORM\Table(name: 'cp_forum_censor_words')]
#[ORM\UniqueConstraint(name: 'uniq_forum_censor_word', columns: ['word'])]
class ForumCensorWord
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(type: 'string', length: 100)]
    private string $word;

    #[ORM\Column(type: 'string', length: 100, nullable: true)]
    private ?string $replacement = null;

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    public function __construct(string $word, ?string $replacement = null)
    {
        $this->word = mb_strtolower(trim($word));
        $this->replacement = $replacement !== null && $replacement !== '' ? $replacement : null;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getWord(): string
    {
        return $this->word;
    }

    public function getReplacement(): ?string
    {
        return $this->replacement;
    }

    public function setReplacement(?string $replacement): static
    {
        $this->replacement = $replacement !== null && $replacement !== '' ? $replacement : null;

        return $this;
    }
}
