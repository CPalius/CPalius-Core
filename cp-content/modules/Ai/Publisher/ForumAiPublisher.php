<?php

declare(strict_types=1);

namespace Modules\Ai\Publisher;

use App\Core\Localization\LocaleProvider;
use App\Entity\User;
use Modules\Ai\Service\AiTranslationException;
use Modules\Ai\Service\AiTranslatorService;
use Modules\Forum\Entity\ForumPost;
use Modules\Forum\Entity\ForumSection;
use Modules\Forum\Entity\ForumTopic;
use Modules\Forum\Repository\ForumSectionRepository;
use Modules\Forum\Repository\ForumTopicRepository;
use Modules\Forum\Service\ForumSectionHierarchyService;
use Modules\Forum\Service\ForumTopicService;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Writes a translated Forum topic. Forum services are optional so Ai boots without Forum.
 */
final class ForumAiPublisher
{
    public function __construct(
        private readonly AiTranslatorService $translator,
        private readonly LocaleProvider $localeProvider,
        private readonly LoggerInterface $logger,
        private readonly ?ForumTopicService $topicService = null,
        private readonly ?ForumSectionRepository $sectionRepository = null,
        private readonly ?ForumSectionHierarchyService $hierarchy = null,
        private readonly ?ForumTopicRepository $topicRepository = null,
    ) {
    }

    public function isAvailable(): bool
    {
        return $this->topicService instanceof ForumTopicService
            && $this->sectionRepository instanceof ForumSectionRepository
            && $this->topicRepository instanceof ForumTopicRepository;
    }

    /**
     * @return list<string> target locale codes that were published
     */
    public function publish(ForumTopic $topic, ForumPost $opening, User $author, Request $request): array
    {
        if (!$this->isAvailable() || !$this->translator->isConfigured()) {
            throw new AiTranslationException('ai.error.not_configured');
        }

        $topicService = $this->topicService;
        if (!$topicService instanceof ForumTopicService) {
            throw new AiTranslationException('ai.error.not_configured');
        }

        $published = [];
        $existing = [];
        $attempted = 0;
        $lastFailure = null;
        $sourceLocale = $topic->getLocale();
        $groupId = $topicService->ensureTranslationGroup($topic);

        foreach ($this->localeProvider->getCodes() as $targetLocale) {
            if ($targetLocale === $sourceLocale) {
                continue;
            }

            $sibling = $this->topicRepository?->findLocaleSibling($topic, $targetLocale);
            if ($sibling instanceof ForumTopic) {
                $existing[] = $targetLocale;
                continue;
            }

            $section = $this->resolveSection($topic->getSection(), $targetLocale);
            if (!$section instanceof ForumSection) {
                $this->logger->warning('AI forum translation skipped: no target section.', [
                    'source_locale' => $sourceLocale,
                    'target_locale' => $targetLocale,
                    'topic_id' => $topic->getId(),
                ]);
                continue;
            }

            $attempted++;

            try {
                $result = $this->translator->translate(
                    $sourceLocale,
                    $targetLocale,
                    $topic->getTitle(),
                    $opening->getBody(),
                    (string) $topic->getDescription(),
                );

                $topicService->createTopic(
                    $section,
                    $author,
                    $result->title,
                    $result->description,
                    $result->body,
                    $topic->getMode() === ForumTopic::MODE_PRIVATE,
                    $request,
                    null,
                    $targetLocale,
                    false,
                    $groupId,
                );
                $published[] = $targetLocale;
            } catch (AiTranslationException $e) {
                $lastFailure = $e;
                $this->logger->warning('AI forum translation failed for a locale.', [
                    'target_locale' => $targetLocale,
                    'topic_id' => $topic->getId(),
                    'error' => $e->getMessage(),
                ]);
            } catch (\Throwable $e) {
                $lastFailure = new AiTranslationException('ai.error.unreachable', 0, $e);
                $this->logger->warning('AI forum translation failed for a locale.', [
                    'target_locale' => $targetLocale,
                    'topic_id' => $topic->getId(),
                    'error' => $e->getMessage(),
                ]);
            }
        }

        if ($published === [] && $existing !== [] && $attempted === 0) {
            throw new AiTranslationException('ai.flash.already_exists');
        }

        if ($published === [] && $attempted > 0) {
            throw $lastFailure ?? new AiTranslationException('ai.error.unreachable');
        }

        return $published;
    }

    private function resolveSection(ForumSection $source, string $targetLocale): ?ForumSection
    {
        $sibling = $this->sectionRepository?->findLocaleSibling($source, $targetLocale);
        if ($sibling instanceof ForumSection && $sibling->allowsTopics()) {
            return $sibling;
        }

        $boards = $this->hierarchy?->getTopicBoards($targetLocale) ?? [];

        return $boards[0] ?? null;
    }
}
