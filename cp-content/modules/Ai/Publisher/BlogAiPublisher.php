<?php

declare(strict_types=1);

namespace Modules\Ai\Publisher;

use App\Core\Content\RichTextSanitizer;
use App\Core\Content\SlugGenerator;
use App\Core\Localization\LocaleProvider;
use App\Core\OriginCache\OriginCachePurger;
use App\Core\Taxonomy\Entity\Term;
use App\Entity\Node;
use App\Repository\CategoryRepository;
use App\Repository\NodeRepository;
use App\Repository\TagRepository;
use Doctrine\ORM\EntityManagerInterface;
use Modules\Ai\Service\AiTranslationException;
use Modules\Ai\Service\AiTranslatorService;
use Symfony\Component\Uid\Uuid;

/**
 * Writes a translated Blog node into the same translation group.
 */
final class BlogAiPublisher
{
    public function __construct(
        private readonly AiTranslatorService $translator,
        private readonly LocaleProvider $localeProvider,
        private readonly EntityManagerInterface $entityManager,
        private readonly NodeRepository $nodeRepository,
        private readonly SlugGenerator $slugGenerator,
        private readonly OriginCachePurger $originCachePurger,
        private readonly RichTextSanitizer $richTextSanitizer,
        private readonly ?CategoryRepository $categoryRepository = null,
        private readonly ?TagRepository $tagRepository = null,
    ) {
    }

    /**
     * @return list<string>
     */
    public function publish(Node $source): array
    {
        if ($source->getType() !== 'post') {
            return [];
        }

        if (!$this->translator->isConfigured()) {
            throw new AiTranslationException('ai.error.not_configured');
        }

        $groupId = $source->getTranslationGroupId();
        if (!$groupId instanceof Uuid) {
            $source->assignToNewTranslationGroup();
            $this->entityManager->flush();
            $groupId = $source->getTranslationGroupId();
        }
        if (!$groupId instanceof Uuid) {
            throw new AiTranslationException('ai.error.unreachable');
        }

        $existing = [];
        foreach ($this->nodeRepository->findTranslations($groupId) as $sibling) {
            $existing[$sibling->getLocale()] = $sibling;
        }

        $published = [];
        $attempted = 0;
        $hadSiblings = false;
        $lastFailure = null;
        $sourceLocale = $source->getLocale();
        $body = (string) $source->getDataValue('body', '');
        $excerpt = (string) $source->getDataValue('excerpt', '');

        foreach ($this->localeProvider->getCodes() as $targetLocale) {
            if ($targetLocale === $sourceLocale) {
                continue;
            }
            if (isset($existing[$targetLocale])) {
                $hadSiblings = true;
                continue;
            }

            $attempted++;

            try {
                $result = $this->translator->translate(
                    $sourceLocale,
                    $targetLocale,
                    $source->getTitle(),
                    $body,
                    '',
                    $excerpt,
                );

                $node = new Node(
                    $result->title,
                    $this->slugGenerator->generate($result->title, $targetLocale),
                    'post',
                    $targetLocale,
                );
                $node->joinTranslationGroup($groupId);
                if ($source->getAuthor() !== null) {
                    $node->setAuthor($source->getAuthor());
                }

                $this->copyPublication($source, $node);
                $node->setDataValue('excerpt', $result->excerpt !== '' ? $result->excerpt : $result->description);
                $node->setDataValue('body', $this->richTextSanitizer->sanitize($result->body));
                $node->setDataValue('is_featured', $source->getDataValue('is_featured', 0));
                $node->setDataValue('comments_enabled', $source->getDataValue('comments_enabled', 1));
                $node->setDataValue('post_sub_type', $source->getDataValue('post_sub_type'));
                $node->setDataValue('type_fields', $source->getDataValue('type_fields', []));
                $node->setDataValue('featured_image_asset_id', $source->getDataValue('featured_image_asset_id'));
                $node->setDataValue('seo', $source->getDataValue('seo', []));
                $this->copyTaxonomy($source, $node, $targetLocale);

                $this->entityManager->persist($node);
                $this->entityManager->flush();
                $existing[$targetLocale] = $node;
                $published[] = $targetLocale;
            } catch (AiTranslationException $e) {
                $lastFailure = $e;
            } catch (\Throwable $e) {
                $lastFailure = new AiTranslationException('ai.error.unreachable', 0, $e);
            }
        }

        if ($published === [] && $hadSiblings && $attempted === 0) {
            throw new AiTranslationException('ai.flash.already_exists_node');
        }

        if ($published === [] && $attempted > 0) {
            throw $lastFailure ?? new AiTranslationException('ai.error.unreachable');
        }

        if ($published !== []) {
            $this->originCachePurger->purgeAreas('blog', 'home', 'roadmap');
        }

        return $published;
    }

    private function copyPublication(Node $source, Node $target): void
    {
        $status = $source->getStatus();
        if ($status === Node::STATUS_PUBLISHED) {
            $target->publish($source->getPublishedAt() ?? new \DateTimeImmutable());

            return;
        }

        $target->setStatus($status);
        $scheduled = $source->getDataValue('scheduled_for');
        if (\is_string($scheduled) && $scheduled !== '') {
            $target->setDataValue('scheduled_for', $scheduled);
        }
    }

    private function copyTaxonomy(Node $source, Node $target, string $targetLocale): void
    {
        $mapped = [];
        foreach ($source->getCategories() as $category) {
            if (!$category instanceof Term) {
                continue;
            }
            $sibling = $this->categoryRepository?->findTranslation($category, $targetLocale);
            if ($sibling instanceof Term) {
                $target->addCategory($sibling);
                $mapped[] = $sibling;
            }
        }

        if ($mapped !== []) {
            $target->setCategory($mapped[0]);
        } else {
            $fallback = $this->categoryRepository?->findByLocale($targetLocale)[0]
                ?? $this->categoryRepository?->findByLocale($this->localeProvider->getDefaultCode())[0]
                ?? null;
            if ($fallback instanceof Term) {
                $target->addCategory($fallback);
                $target->setCategory($fallback);
            }
        }

        if ($this->tagRepository === null) {
            return;
        }

        $names = [];
        foreach ($source->getTags() as $tag) {
            $names[] = $tag->getName();
        }
        if ($names === []) {
            return;
        }

        foreach ($this->tagRepository->findOrCreateByNames($names, $targetLocale) as $tag) {
            $target->addTag($tag);
        }
    }
}
