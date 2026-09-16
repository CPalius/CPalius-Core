<?php

declare(strict_types=1);

namespace Modules\Showcase\Service;

use App\Core\Field\FieldValuePersister;
use App\Core\Hook\HookContext;
use App\Core\Hook\HookDispatcherInterface;
use App\Core\OriginCache\OriginCachePurger;
use App\Core\Taxonomy\Entity\Term;
use App\Core\Taxonomy\Repository\TermRepository;
use App\Core\TextFormat\TextFormatAccess;
use App\Core\TextFormat\TextFormatProcessor;
use App\Core\TextFormat\TextFormatRegistry;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Modules\Showcase\Dto\ShowcaseItemInput;
use Modules\Showcase\Entity\ShowcaseItem;
use Modules\Showcase\Entity\ShowcaseType;
use Modules\Showcase\Repository\ShowcaseItemRepository;

/**
 * The only place a showcase item is written.
 *
 * Both the member submission form and the admin screen come through here, so the
 * sanitization rules cannot be weaker on the public side than in the panel:
 *
 *   - the body goes through TextFormatProcessor::sanitizeForStorage(), which is
 *     the core's "the database is never an XSS warehouse" gate (Law 5.3);
 *   - the text format is resolved through TextFormatAccess, so a member cannot
 *     post full_html by editing a hidden input;
 *   - custom field values go through FieldValuePersister, which normalizes and
 *     validates each value against its field type and writes ONLY keys backed by
 *     a FieldDefinition — mass assignment into data[] is impossible;
 *   - URLs go through ShowcaseUrlValidator;
 *   - categories are re-fetched and checked against the type's own vocabulary,
 *     so a posted term id from another vocabulary is dropped.
 */
final class ShowcaseItemManager
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ShowcaseItemRepository $items,
        private readonly ShowcaseSlugger $slugger,
        private readonly FieldValuePersister $fieldValues,
        private readonly ShowcaseFieldIndexer $indexer,
        private readonly ShowcaseUrlValidator $urls,
        private readonly TextFormatProcessor $textFormats,
        private readonly TextFormatAccess $textFormatAccess,
        private readonly TermRepository $terms,
        private readonly ShowcaseTypeManager $typeManager,
        private readonly ShowcaseConfig $config,
        private readonly OriginCachePurger $cachePurger,
        private readonly HookDispatcherInterface $hooks,
    ) {
    }

    /**
     * @return array{item: ?ShowcaseItem, errors: array<string, list<string>>}
     */
    public function create(ShowcaseType $type, ?User $owner, string $locale, ShowcaseItemInput $input, bool $canPublishDirectly): array
    {
        $title = trim(strip_tags($input->title));

        if ($title === '') {
            return ['item' => null, 'errors' => ['title' => ['showcase.items.error.title_required']]];
        }

        $item = new ShowcaseItem($type, $title, $this->slugger->generate($title, $locale, null, $input->slug), $locale);
        $item->setOwner($owner);
        $item->assignToNewTranslationGroup();

        $errors = $this->applyInput($item, $input);

        if ($errors !== []) {
            return ['item' => null, 'errors' => $errors];
        }

        $item->setStatus($this->initialStatus($canPublishDirectly));

        $this->entityManager->persist($item);
        $this->entityManager->flush();

        $this->indexer->sync($item);
        $this->entityManager->flush();

        $this->purge();
        $this->notify('showcase.item.created', $item);

        if ($item->isPublished()) {
            $this->notify('showcase.item.published', $item);
        }

        return ['item' => $item, 'errors' => []];
    }

    /**
     * @return array<string, list<string>> field name => violation keys ([] = saved)
     */
    public function update(ShowcaseItem $item, ShowcaseItemInput $input, bool $canPublishDirectly, bool $resubmit = true): array
    {
        $title = trim(strip_tags($input->title));

        if ($title === '') {
            return ['title' => ['showcase.items.error.title_required']];
        }

        $item->setTitle($title);

        if (trim($input->slug) !== '') {
            $item->setSlug($this->slugger->generate($title, $item->getLocale(), $item->getId(), $input->slug));
        }

        $errors = $this->applyInput($item, $input);

        if ($errors !== []) {
            return $errors;
        }

        // Editing a published entry on a moderated site sends it back to the
        // queue; otherwise an approved listing could be rewritten into anything
        // after the fact. A moderator editing on someone's behalf keeps it live.
        if ($resubmit && $this->config->requiresApproval() && !$canPublishDirectly && $item->isPublished()) {
            $item->setStatus(ShowcaseItem::STATUS_PENDING);
        }

        if ($resubmit && $item->getStatus() === ShowcaseItem::STATUS_REJECTED) {
            $item->setStatus($this->initialStatus($canPublishDirectly));
            $item->setModerationNote(null);
        }

        $this->entityManager->flush();

        $this->indexer->sync($item);
        $this->entityManager->flush();

        $this->purge();

        return [];
    }

    /**
     * Creates the sibling row for another language, sharing the translation group
     * so the two stay linked (Law 5.2). Field values are copied as a starting
     * point; translatable ones are then edited independently.
     */
    public function createTranslation(ShowcaseItem $source, string $locale): ?ShowcaseItem
    {
        $groupId = $source->ensureTranslationGroup();

        if ($this->items->findOneByTranslationGroup($groupId, $locale) instanceof ShowcaseItem) {
            return null;
        }

        $item = new ShowcaseItem(
            $source->getType(),
            $source->getTitle(),
            $this->slugger->generate($source->getTitle(), $locale),
            $locale,
        );

        $item->joinTranslationGroup($groupId);
        $item->setOwner($source->getOwner());
        $item->setSummary($source->getSummary());
        $item->setBody($source->getBody());
        $item->setBodyFormat($source->getBodyFormat());
        $item->setPriceMode($source->getPriceMode());
        $item->setPrice($source->getPrice());
        $item->setPriceCurrency($source->getPriceCurrency());
        $item->setExternalUrl($source->getExternalUrl());
        $item->setDemoUrl($source->getDemoUrl());
        $item->setContactEmail($source->getContactEmail());
        $item->setContactPhone($source->getContactPhone());
        $item->setLocation($source->getLocation());
        $item->setCoverAssetId($source->getCoverAssetId());
        $item->setFieldableData($source->getFieldableData());
        $item->setStatus(ShowcaseItem::STATUS_DRAFT);

        $this->entityManager->persist($item);
        $this->entityManager->flush();

        $this->indexer->sync($item);
        $this->entityManager->flush();

        return $item;
    }

    public function publish(ShowcaseItem $item): void
    {
        $item->setStatus(ShowcaseItem::STATUS_PUBLISHED);
        $item->setModerationNote(null);

        if (!$item->getPublishedAt() instanceof \DateTimeImmutable) {
            $item->setPublishedAt(new \DateTimeImmutable());
        }

        $this->entityManager->flush();
        $this->purge();
        $this->notify('showcase.item.published', $item);
    }

    public function reject(ShowcaseItem $item, string $reason): void
    {
        $item->setStatus(ShowcaseItem::STATUS_REJECTED);
        $item->setModerationNote($reason);

        $this->entityManager->flush();
        $this->purge();
    }

    public function archive(ShowcaseItem $item): void
    {
        $item->setStatus(ShowcaseItem::STATUS_ARCHIVED);

        $this->entityManager->flush();
        $this->purge();
    }

    public function submitForReview(ShowcaseItem $item, bool $canPublishDirectly): void
    {
        $item->setStatus($this->initialStatus($canPublishDirectly));
        $item->setModerationNote(null);

        $this->entityManager->flush();
        $this->purge();
    }

    public function setFeatured(ShowcaseItem $item, bool $featured): void
    {
        $item->setFeatured($featured);

        $this->entityManager->flush();
        $this->purge();
    }

    /**
     * Recycle bin, not deletion — a moderator mis-click must be undoable, and the
     * item's media and links stay attached for the restore.
     */
    public function softDelete(ShowcaseItem $item): void
    {
        $item->softDelete();

        $this->entityManager->flush();
        $this->purge();
    }

    public function restore(ShowcaseItem $item): void
    {
        $item->restore();

        $this->entityManager->flush();
        $this->purge();
    }

    /**
     * Permanent removal. Media rows, links, reviews and index rows go with it
     * through ON DELETE CASCADE; the Media assets themselves are shared storage
     * and stay in the library.
     */
    public function purgeItem(ShowcaseItem $item): void
    {
        $this->entityManager->remove($item);
        $this->entityManager->flush();
        $this->purge();
    }

    /**
     * Records a view without a full UPDATE of the entity: a listing page hit must
     * not write the whole row (and must not bump updatedAt, which would reshuffle
     * "recently updated" ordering on every visit).
     */
    public function recordView(ShowcaseItem $item): void
    {
        $id = $item->getId();

        if ($id === null) {
            return;
        }

        try {
            $this->entityManager->getConnection()->executeStatement(
                'UPDATE cp_showcase_items SET view_count = view_count + 1 WHERE id = :id',
                ['id' => $id],
            );
        } catch (\Throwable) {
            // A counter is not worth a 500 on a content page.
        }
    }

    public function recordClick(ShowcaseItem $item): void
    {
        $id = $item->getId();

        if ($id === null) {
            return;
        }

        try {
            $this->entityManager->getConnection()->executeStatement(
                'UPDATE cp_showcase_items SET click_count = click_count + 1 WHERE id = :id',
                ['id' => $id],
            );
        } catch (\Throwable) {
            // Same as recordView(): telemetry must never break the redirect.
        }
    }

    /**
     * Shared write path for create() and update().
     *
     * @return array<string, list<string>>
     */
    private function applyInput(ShowcaseItem $item, ShowcaseItemInput $input): array
    {
        $type = $item->getType();

        $item->setSummary($input->summary);

        if ($type->supports('body')) {
            $format = $this->textFormatAccess->resolve(
                $input->bodyFormat !== '' ? $input->bodyFormat : TextFormatRegistry::RESTRICTED,
                TextFormatRegistry::RESTRICTED,
            );
            $item->setBodyFormat($format);
            $item->setBody($input->body !== '' ? $this->textFormats->sanitizeForStorage($input->body, $format) : null);
        } else {
            $item->setBody(null);
        }

        $this->applyPrice($item, $input);

        $item->setExternalUrl($type->supports('external_url') ? $this->urls->sanitize($input->externalUrl) : null);
        $item->setDemoUrl($type->supports('demo_url') ? $this->urls->sanitize($input->demoUrl) : null);

        if ($type->supports('contact')) {
            $item->setContactEmail($input->contactEmail);
            $item->setContactPhone($input->contactPhone);
        } else {
            $item->setContactEmail(null);
            $item->setContactPhone(null);
        }

        $item->setLocation($type->supports('location') ? $input->location : null);
        $item->setExpiresAt($this->parseDate($input->expiresAt));

        if ($input->coverAssetId !== null) {
            $item->setCoverAssetId($input->coverAssetId);
        }

        $this->applyTerms($item, $input->termIds);

        // Field API last: it validates against the bundle's definitions and
        // reports violations keyed by field name, which the caller shows inline.
        $violations = $this->fieldValues->persist($item, $input->fields, $item->getLocale());

        return $violations;
    }

    private function applyPrice(ShowcaseItem $item, ShowcaseItemInput $input): void
    {
        if (!$item->getType()->supports('price')) {
            $item->setPriceMode(ShowcaseItem::PRICE_NONE);
            $item->setPrice(null);
            $item->setPriceCurrency(null);

            return;
        }

        $mode = \in_array($input->priceMode, ShowcaseItem::PRICE_MODES, true)
            ? $input->priceMode
            : ShowcaseItem::PRICE_NONE;

        $item->setPriceMode($mode);

        $needsAmount = \in_array($mode, [ShowcaseItem::PRICE_FIXED, ShowcaseItem::PRICE_STARTING, ShowcaseItem::PRICE_SUBSCRIPTION], true);
        $amount = str_replace([' ', ','], ['', '.'], trim($input->price));

        if ($needsAmount && is_numeric($amount) && (float) $amount >= 0) {
            $item->setPrice($amount);
            $currency = $input->priceCurrency !== '' ? $input->priceCurrency : $this->config->defaultCurrency();
            $item->setPriceCurrency($currency);

            return;
        }

        $item->setPrice(null);
        $item->setPriceCurrency(null);
    }

    /**
     * @param list<int> $termIds
     */
    private function applyTerms(ShowcaseItem $item, array $termIds): void
    {
        $item->clearTerms();

        $type = $item->getType();

        if (!$type->supports('categories') || $termIds === []) {
            return;
        }

        $vocabulary = $this->typeManager->vocabularyOf($type);

        if ($vocabulary === null) {
            return;
        }

        foreach ($this->terms->findByIds($termIds) as $term) {
            // A posted id must belong to THIS type's vocabulary and THIS locale:
            // otherwise a crafted form could file a car under a software category
            // or attach a term from a language the item is not written in.
            if (!$term instanceof Term
                || $term->getVocabulary()->getId() !== $vocabulary->getId()
                || $term->getLocale() !== $item->getLocale()
            ) {
                continue;
            }

            $item->addTerm($term);
        }
    }

    private function initialStatus(bool $canPublishDirectly): string
    {
        if ($canPublishDirectly || !$this->config->requiresApproval()) {
            return ShowcaseItem::STATUS_PUBLISHED;
        }

        return ShowcaseItem::STATUS_PENDING;
    }

    private function parseDate(string $raw): ?\DateTimeImmutable
    {
        $raw = trim($raw);

        if ($raw === '') {
            return null;
        }

        try {
            return new \DateTimeImmutable($raw);
        } catch (\Throwable) {
            return null;
        }
    }

    private function purge(): void
    {
        $this->cachePurger->purgeAreas('showcase', 'home');
    }

    /**
     * Announces a lifecycle event on a hook point.
     *
     * This is the extension seam that lets a SITE wire the showcase into whatever
     * else it runs — opening a support thread when a product goes live, pushing a
     * release to a webhook — without that logic being compiled into this module
     * and without the module knowing which other modules exist. HookManager
     * quarantines a failing listener, so a broken integration cannot take a
     * publish with it.
     */
    private function notify(string $hookPoint, ShowcaseItem $item): void
    {
        $this->hooks->trigger($hookPoint, new HookContext([
            'item' => $item,
            'id' => $item->getId(),
            'type' => $item->getType()->getMachineName(),
            'locale' => $item->getLocale(),
            'slug' => $item->getSlug(),
            'status' => $item->getStatus(),
        ]));
    }
}
