<?php

declare(strict_types=1);

namespace Modules\Ai\EventSubscriber;

use Modules\Ai\Publisher\BlogAiPublisher;
use Modules\Ai\Publisher\ForumAiPublisher;
use Modules\Ai\Publisher\PagesAiPublisher;
use Modules\Ai\Service\AiTranslationException;
use Modules\Ai\Service\AiTranslatorService;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Listens by event name so Ai does not hard-require Forum, Blog or Pages classes.
 * Failures stay in the log and a flash; the original row is already flushed.
 */
final class AiTranslationSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly AiTranslatorService $translator,
        private readonly ForumAiPublisher $forumPublisher,
        private readonly BlogAiPublisher $blogPublisher,
        private readonly PagesAiPublisher $pagesPublisher,
        private readonly RequestStack $requestStack,
        private readonly TranslatorInterface $translatorUi,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @return array<string, string>
     */
    public static function getSubscribedEvents(): array
    {
        return [
            'forum.topic.created' => 'onForumTopicCreated',
            'forum.topic.translate' => 'onForumTopicCreated',
            'blog.article.created' => 'onBlogArticleCreated',
            'blog.article.translate' => 'onBlogArticleCreated',
            'page.created' => 'onPageCreated',
            'page.translate' => 'onPageCreated',
        ];
    }

    public function onForumTopicCreated(object $event): void
    {
        if (!$this->wantsTranslate($event) || !$this->forumPublisher->isAvailable()) {
            return;
        }

        if (!method_exists($event, 'getTopic') || !method_exists($event, 'getOpeningPost') || !method_exists($event, 'getAuthor') || !method_exists($event, 'getRequest')) {
            return;
        }

        $this->run(function () use ($event): array {
            return $this->forumPublisher->publish(
                $event->getTopic(),
                $event->getOpeningPost(),
                $event->getAuthor(),
                $event->getRequest(),
            );
        }, 'forum');
    }

    public function onBlogArticleCreated(object $event): void
    {
        if (!$this->wantsTranslate($event) || !method_exists($event, 'getNode')) {
            return;
        }

        $this->run(function () use ($event): array {
            return $this->blogPublisher->publish($event->getNode());
        }, 'blog');
    }

    public function onPageCreated(object $event): void
    {
        if (!$this->wantsTranslate($event) || !method_exists($event, 'getNode')) {
            return;
        }

        $this->run(function () use ($event): array {
            return $this->pagesPublisher->publish($event->getNode());
        }, 'page');
    }

    private function wantsTranslate(object $event): bool
    {
        return method_exists($event, 'shouldTranslate') && $event->shouldTranslate();
    }

    /**
     * @param callable(): list<string> $publish
     */
    private function run(callable $publish, string $channel): void
    {
        if (!$this->translator->isConfigured()) {
            $this->logger->warning('AI auto-translate skipped: module off.', ['channel' => $channel]);
            $this->flash('warning', 'ai.flash.not_configured');

            return;
        }

        try {
            $locales = $publish();
        } catch (AiTranslationException $e) {
            $this->logger->warning('AI auto-translate failed.', [
                'channel' => $channel,
                'error' => $e->getMessage(),
            ]);
            $key = $e->getMessage();
            $this->flash('warning', str_starts_with($key, 'ai.') ? $key : 'ai.flash.failed');

            return;
        } catch (\Throwable $e) {
            $this->logger->error('AI auto-translate crashed.', [
                'channel' => $channel,
                'error' => $e->getMessage(),
            ]);
            $this->flash('warning', 'ai.flash.failed');

            return;
        }

        if ($locales === []) {
            $this->flash('warning', 'ai.flash.no_target');

            return;
        }

        $this->flash('success', 'ai.flash.published', [
            'locales' => implode(', ', $locales),
        ]);
    }

    /**
     * @param array<string, string> $parameters
     */
    private function flash(string $type, string $key, array $parameters = []): void
    {
        try {
            $this->requestStack->getSession()->getFlashBag()->add(
                $type,
                $this->translatorUi->trans($key, $parameters),
            );
        } catch (\Throwable) {
            // No session (CLI / early kernel): the log line is enough.
        }
    }
}
