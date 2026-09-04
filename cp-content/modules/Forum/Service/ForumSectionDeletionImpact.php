<?php

declare(strict_types=1);

namespace Modules\Forum\Service;

/**
 * Impact of deleting a forum structure row — used by the admin confirm screen and safety checks.
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

    /** Children present: delete is blocked to prevent content loss. */
    public function isDeletionAllowed(): bool
    {
        return !$this->hasChildSections();
    }

    /** Topics/posts remain: the admin must type the title to confirm. */
    public function requiresTitleConfirmation(): bool
    {
        return $this->hasTopics();
    }
}
