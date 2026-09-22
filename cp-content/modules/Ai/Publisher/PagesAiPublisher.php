<?php

declare(strict_types=1);

namespace Modules\Ai\Publisher;

use App\Core\Content\SlugGenerator;
use App\Core\Localization\LocaleProvider;
use App\Core\OriginCache\OriginCachePurger;
use App\Entity\Node;
use App\Repository\NodeRepository;
use Doctrine\ORM\EntityManagerInterface;
use Modules\Ai\Service\AiTranslationException;
use Modules\Ai\Service\AiTranslatorService;
use Symfony\Component\Uid\Uuid;

/**
 * Writes a translated Page node into the same translation group.
 */
final class PagesAiPublisher
{
    public function __construct(
        private readonly AiTranslatorService $translator,
        private readonly LocaleProvider $localeProvider,
        private readonly EntityManagerInterface $entityManager,
        private readonly NodeRepository $nodeRepository,
        private readonly SlugGenerator $slugGenerator,
        private readonly OriginCachePurger $originCachePurger,
    ) {
    }

    /**
     * @return list<string>
     */
    public function publish(Node $source): array
    {
        if ($source->getType() !== 'page') {
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
            if ($sibling->getType() === 'page') {
                $existing[$sibling->getLocale()] = $sibling;
            }
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

                $slug = $this->slugGenerator->generate($result->title, $targetLocale);
                if ($this->isReservedSlug($slug)) {
                    $slug = $this->slugGenerator->generate($slug.'-page', $targetLocale);
                }

                $node = new Node($result->title, $slug, 'page', $targetLocale);
                $node->joinTranslationGroup($groupId);
                if ($source->getAuthor() !== null) {
                    $node->setAuthor($source->getAuthor());
                }

                $this->copyPublication($source, $node);
                $node->setDataValue('excerpt', $result->excerpt !== '' ? $result->excerpt : $result->description);
                $node->setDataValue('body', $result->body);
                $node->setDataValue('is_featured', $source->getDataValue('is_featured', 0));
                $node->setDataValue('template', $source->getDataValue('template'));
                $node->setDataValue('featured_image_asset_id', $source->getDataValue('featured_image_asset_id'));
                $node->setDataValue('field_group_id', $source->getDataValue('field_group_id'));
                $node->setDataValue('custom_fields', $source->getDataValue('custom_fields', []));
                $node->setDataValue('custom_css', $source->getDataValue('custom_css', ''));
                $node->setDataValue('custom_js', $source->getDataValue('custom_js', ''));
                $node->setDataValue('seo', $source->getDataValue('seo', []));

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
            $areas = ['home', $source->getSlug()];
            foreach ($published as $locale) {
                $sibling = $existing[$locale] ?? null;
                if ($sibling instanceof Node) {
                    $areas[] = $sibling->getSlug();
                }
            }
            $this->originCachePurger->purgeAreas(...array_values(array_unique($areas)));
        }

        return $published;
    }

    private function isReservedSlug(string $slug): bool
    {
        $class = 'Modules\\Pages\\PageReservedSlugs';
        if (!class_exists($class) || !method_exists($class, 'isReserved')) {
            return false;
        }

        return (bool) $class::isReserved($slug);
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
}
