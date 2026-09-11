<?php

declare(strict_types=1);

namespace Modules\Pages\Form\DTO;

use App\Entity\Node;
use Modules\Pages\PageReservedSlugs;
use Modules\Pages\PageTemplate;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * Form-layer DTO for page CRUD; not a Doctrine entity (Law 3.1).
 */
#[Assert\Callback('validateReservedSlug')]
final class PageFormModel
{
    #[Assert\NotBlank(message: 'pages.validation.title_required')]
    #[Assert\Length(max: 255, maxMessage: 'pages.validation.title_max')]
    public string $title = '';

    #[Assert\Length(max: 255, maxMessage: 'pages.validation.slug_max')]
    #[Assert\Regex(
        pattern: '/^(?:[a-z0-9]+(?:-[a-z0-9]+)*)?$/',
        message: 'pages.validation.slug_format',
        match: true,
    )]
    public ?string $slug = null;

    #[Assert\Length(max: 500, maxMessage: 'pages.validation.excerpt_max')]
    public ?string $excerpt = null;

    public string $body = '';

    public bool $isFeatured = false;

    #[Assert\Choice(
        choices: [Node::STATUS_DRAFT, Node::STATUS_PUBLISHED, Node::STATUS_SCHEDULED],
        message: 'pages.validation.status_invalid',
    )]
    public string $status = Node::STATUS_DRAFT;

    public ?\DateTimeImmutable $publishedAt = null;

    #[Assert\Choice(callback: [PageTemplate::class, 'values'], message: 'pages.validation.template_invalid')]
    public string $template = PageTemplate::DEFAULT;

    #[Assert\Length(max: 160, maxMessage: 'pages.validation.meta_max')]
    public ?string $seoMetaDescription = null;

    #[Assert\Length(max: 255, maxMessage: 'pages.validation.focus_keyword_max')]
    public ?string $seoFocusKeyword = null;

    #[Assert\Length(max: 255, maxMessage: 'pages.validation.canonical_max')]
    #[Assert\Url(message: 'pages.validation.url_invalid', requireTld: true)]
    public ?string $seoCanonicalUrl = null;

    public bool $seoNoindex = false;

    public ?int $featuredImageAssetId = null;

    public ?int $fieldGroupId = null;

    /**
     * JSON-encoded ACF rows from the Studio builder.
     */
    public string $customFieldsJson = '[]';

    public ?string $customCss = null;

    public ?string $customJs = null;

    public function validateReservedSlug(ExecutionContextInterface $context): void
    {
        $slug = trim((string) $this->slug);
        if ($slug !== '' && PageReservedSlugs::isReserved($slug)) {
            $context->buildViolation('pages.validation.reserved_slug')
                ->atPath('slug')
                ->addViolation();
        }
    }
}
