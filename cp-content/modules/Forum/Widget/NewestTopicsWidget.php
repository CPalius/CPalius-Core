<?php

declare(strict_types=1);

namespace Modules\Forum\Widget;

/** Topics opened most recently. */
final class NewestTopicsWidget extends ForumTopicListWidget
{
    protected function name(): string
    {
        return 'newest_topics';
    }

    public function icon(): string
    {
        return 'heroicons:sparkles';
    }

    protected function load(int $limit, string $locale): array
    {
        return $this->topics->findNewestOpened($limit, 0, $locale);
    }
}
