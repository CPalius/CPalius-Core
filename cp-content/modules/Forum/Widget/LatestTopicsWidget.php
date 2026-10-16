<?php

declare(strict_types=1);

namespace Modules\Forum\Widget;

/** Topics with the latest activity. */
final class LatestTopicsWidget extends ForumTopicListWidget
{
    protected function name(): string
    {
        return 'latest_topics';
    }

    public function icon(): string
    {
        return 'heroicons:chat-bubble-left-right';
    }

    protected function load(int $limit, string $locale): array
    {
        return $this->topics->findLatest($limit, 0, $locale);
    }
}
