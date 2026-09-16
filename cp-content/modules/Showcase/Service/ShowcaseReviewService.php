<?php

declare(strict_types=1);

namespace Modules\Showcase\Service;

use App\Entity\User;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Modules\Showcase\Entity\ShowcaseItem;
use Modules\Showcase\Entity\ShowcaseReview;
use Modules\Showcase\Repository\ShowcaseReviewRepository;

/**
 * Ratings and short reviews on showcase entries.
 *
 * The rating average on the item is never incremented — it is recomputed from the
 * approved rows after every change. Incrementing would drift the moment a review
 * is rejected, edited or deleted, and a wrong average on a marketplace listing is
 * worse than no average at all.
 */
final class ShowcaseReviewService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ShowcaseReviewRepository $reviews,
        private readonly ShowcaseConfig $config,
    ) {
    }

    /**
     * Creates or updates the author's single review for an item.
     *
     * @return array{review: ?ShowcaseReview, error: ?string}
     */
    public function submit(ShowcaseItem $item, User $author, int $rating, ?string $body): array
    {
        if ($rating < ShowcaseReview::MIN_RATING || $rating > ShowcaseReview::MAX_RATING) {
            return ['review' => null, 'error' => 'showcase.reviews.error.rating_range'];
        }

        $review = $this->reviews->findOneByItemAndAuthor($item, $author);

        if ($review === null) {
            $review = new ShowcaseReview($item, $author, $rating);
            $this->entityManager->persist($review);
        }

        $review->setRating($rating);
        $review->setBody($body);
        $review->setStatus($this->config->reviewsRequireApproval()
            ? ShowcaseReview::STATUS_PENDING
            : ShowcaseReview::STATUS_APPROVED);

        try {
            $this->entityManager->flush();
        } catch (UniqueConstraintViolationException) {
            // Two tabs, one member: the unique key did its job. Nothing was lost —
            // the first submission is stored — so report it as a duplicate rather
            // than a failure.
            return ['review' => null, 'error' => 'showcase.reviews.error.duplicate'];
        }

        $this->recomputeAggregate($item);

        return ['review' => $review, 'error' => null];
    }

    public function approve(ShowcaseReview $review): void
    {
        $review->setStatus(ShowcaseReview::STATUS_APPROVED);
        $this->entityManager->flush();
        $this->recomputeAggregate($review->getItem());
    }

    public function reject(ShowcaseReview $review): void
    {
        $review->setStatus(ShowcaseReview::STATUS_REJECTED);
        $this->entityManager->flush();
        $this->recomputeAggregate($review->getItem());
    }

    public function delete(ShowcaseReview $review): void
    {
        $item = $review->getItem();

        $this->entityManager->remove($review);
        $this->entityManager->flush();

        $this->recomputeAggregate($item);
    }

    public function recomputeAggregate(ShowcaseItem $item): void
    {
        $aggregate = $this->reviews->aggregateApproved($item);

        $item->setRatingAggregate($aggregate['sum'], $aggregate['count']);

        $this->entityManager->flush();
    }
}
