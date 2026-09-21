<?php

declare(strict_types=1);

namespace Modules\Forum\Entity;

use Doctrine\ORM\Mapping as ORM;
use Modules\Forum\ForumBanFilterType;
use Modules\Forum\Repository\ForumBanFilterRepository;

/**
 * IP / email / username mask (cp_forum_ban_filters).
 * rule holds a CIDR, wildcard, or exact string — matching is a future service.
 */
#[ORM\Entity(repositoryClass: ForumBanFilterRepository::class)]
#[ORM\Table(name: 'cp_forum_ban_filters')]
#[ORM\UniqueConstraint(name: 'uniq_forum_ban_filter_slot', columns: ['type', 'rule'])]
#[ORM\Index(columns: ['type'], name: 'idx_forum_ban_filter_type')]
class ForumBanFilter
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(name: 'type', type: 'string', length: 8, enumType: ForumBanFilterType::class)]
    private ForumBanFilterType $type;

    #[ORM\Column(type: 'string', length: 255)]
    private string $rule;

    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private ?string $reason = null;

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    public function __construct(ForumBanFilterType $type, string $rule, ?string $reason = null)
    {
        $this->type = $type;
        $this->rule = trim($rule);
        $this->reason = $reason !== null && $reason !== '' ? mb_substr($reason, 0, 255) : null;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getType(): ForumBanFilterType
    {
        return $this->type;
    }

    public function setType(ForumBanFilterType $type): static
    {
        $this->type = $type;

        return $this;
    }

    public function getRule(): string
    {
        return $this->rule;
    }

    public function setRule(string $rule): static
    {
        $this->rule = trim($rule);

        return $this;
    }

    public function getReason(): ?string
    {
        return $this->reason;
    }

    public function setReason(?string $reason): static
    {
        $this->reason = $reason !== null && $reason !== '' ? mb_substr($reason, 0, 255) : null;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
