<?php

declare(strict_types=1);

namespace Modules\Forum\Service;

/**
 * Bir forum yapı kaydının silinmesinin etkisi — admin onay ekranı ve
 * güvenlik kontrolleri için kullanılır.
 */
final readonly class ForumSectionDeletionImpact
{
    /**
     * @param list<string> $childSectionTitles
     */
    public function __construct(
        public int $childSectionCount,
        public int $topicCount,
        public int $postCount,
        public array $childSectionTitles,
    ) {
    }

    public function hasChildSections(): bool
    {
        return $this->childSectionCount > 0;
    }

    public function hasTopics(): bool
    {
        return $this->topicCount > 0;
    }

    /** Alt kayıt varsa silme tamamen engellenir (içerik kaybını önler). */
    public function isDeletionAllowed(): bool
    {
        return !$this->hasChildSections();
    }

    /** Konu/mesaj varsa başlık yazarak onay zorunludur. */
    public function requiresTitleConfirmation(): bool
    {
        return $this->hasTopics();
    }
}
