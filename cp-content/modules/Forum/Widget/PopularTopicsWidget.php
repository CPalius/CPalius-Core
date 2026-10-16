<?php

declare(strict_types=1);

namespace Modules\Forum\Widget;

/** The most read topics. */
final class PopularTopicsWidget extends ForumTopicListWidget
{
    protected function name(): string
    {
        return 'popular_topics';
    }

    public function icon(): string
    {
        return 'heroicons:fire';
    }

    protected function load(int $limit, string $locale): array
    {
        return $this->topics->findPopular($limit, $locale);
    }
}
