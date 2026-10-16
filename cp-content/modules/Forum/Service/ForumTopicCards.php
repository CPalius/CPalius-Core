<?php

declare(strict_types=1);

namespace Modules\Forum\Service;

use App\Core\Settings\SettingsRegistry;
use Modules\Forum\Entity\ForumSection;
use Modules\Forum\Entity\ForumTopic;
use Modules\Forum\Repository\ForumTopicRepository;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * The "latest" and "popular" topic cards under the forum pages. Studio → Forum → Settings decides
 * whether they show, which category they read from and how many rows they list.
 */
final class ForumTopicCards
{
    public const MAX_LIMIT = 10;

    public function __construct(
        private readonly SettingsRegistry $settings,
        private readonly ForumTopicRepository $topics,
        private readonly ForumSectionHierarchyService $hierarchy,
        private readonly ForumWordFilterService $wordFilter,
        private readonly Security $security,
    ) {
    }

    public function enabled(): bool
    {
        return (bool) $this->settings->get('forum.cards_enabled', true);
    }

    public function limit(): int
    {
        return max(1, min(self::MAX_LIMIT, (int) $this->settings->get('forum.cards_limit', 5)));
    }

    /** Code of the chosen category, or '' for every category. */
    public function sectionCode(): string
    {
        return trim((string) $this->settings->get('forum.cards_section', ''));
    }

    /**
     * @return array{latest: list<ForumTopic>|null, popular: list<ForumTopic>|null}|null null when the cards are off
     */
    public function load(string $locale): ?array
    {
        $showLatest = (bool) $this->settings->get('forum.cards_show_latest', true);
        $showPopular = (bool) $this->settings->get('forum.cards_show_popular', true);
        if (!$this->enabled() || (!$showLatest && !$showPopular)) {
            return null;
        }

        $ids = $this->sectionIds($locale);
        $terms = $this->wordFilter->termsForViewer();

        return [
            'latest' => $showLatest ? $this->topics->findInSections($ids, false, $this->limit(), $locale, $terms) : null,
            'popular' => $showPopular ? $this->topics->findInSections($ids, true, $this->limit(), $locale, $terms) : null,
        ];
    }

    /**
     * Sections the visitor may read, narrowed to the chosen category and everything below it.
     *
     * @return list<int>
     */
    private function sectionIds(string $locale): array
    {
        $code = $this->sectionCode();
        $ids = [];

        foreach ($this->hierarchy->getAllSections($locale) as $section) {
            $id = $section->getId();
            if ($id === null || !$this->readable($section)) {
                continue;
            }
            if ($code !== '' && !$this->isInside($section, $code)) {
                continue;
            }
            $ids[] = $id;
        }

        return $ids;
    }

    private function isInside(ForumSection $section, string $code): bool
    {
        for ($cursor = $section; $cursor instanceof ForumSection; $cursor = $cursor->getParent()) {
            if ($cursor->getCode() === $code) {
                return true;
            }
        }

        return false;
    }

    /** Same rule as opening the section: node permission plus the capability of every ancestor. */
    private function readable(ForumSection $section): bool
    {
        if (!$this->security->isGranted('forum.node.view', $section)) {
            return false;
        }

        for ($cursor = $section; $cursor instanceof ForumSection; $cursor = $cursor->getParent()) {
            $capability = $cursor->getRequiredCapability();
            if ($capability !== null && $capability !== '' && !$this->security->isGranted($capability)) {
                return false;
            }
        }

        return true;
    }
}
