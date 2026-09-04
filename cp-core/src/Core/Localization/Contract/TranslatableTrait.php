<?php

declare(strict_types=1);

namespace App\Core\Localization\Contract;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * Shared TranslatableInterface implementation: maps translation_group_id (UUID v7, not an FK).
 * Indexes stay on the entity (unique names). Optional touch() is called when the group changes.
 */
trait TranslatableTrait
{
    /**
     * Logical id linking language rows of the same content. Not a foreign key.
     */
    #[ORM\Column(name: 'translation_group_id', type: UuidType::NAME, nullable: true)]
    private ?Uuid $translationGroupId = null;

    public function getTranslationGroupId(): ?Uuid
    {
        return $this->translationGroupId;
    }

    public function assignToNewTranslationGroup(): static
    {
        $this->translationGroupId = Uuid::v7();
        $this->touchIfSupported();

        return $this;
    }

    public function joinTranslationGroup(Uuid $translationGroupId): static
    {
        $this->translationGroupId = $translationGroupId;
        $this->touchIfSupported();

        return $this;
    }

    public function leaveTranslationGroup(): static
    {
        $this->translationGroupId = null;
        $this->touchIfSupported();

        return $this;
    }

    public function ensureTranslationGroup(): Uuid
    {
        if (!$this->translationGroupId instanceof Uuid) {
            $this->assignToNewTranslationGroup();
        }

        /** @var Uuid $translationGroupId assignToNewTranslationGroup() always fills this */
        $translationGroupId = $this->translationGroupId;

        return $translationGroupId;
    }

    private function touchIfSupported(): void
    {
        if (method_exists($this, 'touch')) {
            $this->touch();
        }
    }
}
