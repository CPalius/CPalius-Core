<?php

declare(strict_types=1);

namespace Modules\Blog\Form\DTO;

use App\Entity\Node;
use Modules\Blog\PostSubType;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * Form-layer DTO for post CRUD; not a Doctrine entity (Law 3.1). Body is sanitized in mapDtoToNode().
 * noteCodeSnippet stays plain text (never |raw / RichTextSanitizer).
 */
#[Assert\Callback('validatePostSubTypeFields')]
final class PostFormModel
{
    #[Assert\NotBlank(message: 'blog.validation.title_required')]
    #[Assert\Length(max: 255, maxMessage: 'blog.validation.title_max')]
    public string $title = '';

    /**
     * Optional; empty slug is generated from title in PostAdminController.
     */
    #[Assert\Length(max: 255, maxMessage: 'blog.validation.slug_max')]
    #[Assert\Regex(
        pattern: '/^[a-z0-9]+(?:-[a-z0-9]+)*$/',
        message: 'blog.validation.slug_format',
        match: true,
    )]
    public ?string $slug = null;

    #[Assert\Length(max: 500, maxMessage: 'blog.validation.excerpt_max')]
    public ?string $excerpt = null;

    #[Assert\NotBlank(message: 'blog.validation.body_required')]
    public string $body = '';

    public bool $isFeatured = false;

    public bool $commentsEnabled = true;

    /**
     * Raw Studio status; resolveEffectiveStatus() may coerce published+future to scheduled.
     */
    #[Assert\Choice(
        choices: [Node::STATUS_DRAFT, Node::STATUS_PUBLISHED, Node::STATUS_SCHEDULED],
        message: 'blog.validation.status_invalid',
    )]
    public string $status = Node::STATUS_DRAFT;

    /**
     * Studio publish-at; empty + published becomes now in resolveEffectiveStatus().
     */
    public ?\DateTimeImmutable $publishedAt = null;

    #[Assert\Length(max: 160, maxMessage: 'blog.validation.meta_max')]
    public ?string $seoMetaDescription = null;

    #[Assert\Length(max: 255, maxMessage: 'blog.validation.focus_keyword_max')]
    public ?string $seoFocusKeyword = null;

    #[Assert\Length(max: 255, maxMessage: 'blog.validation.canonical_max')]
    #[Assert\Url(message: 'blog.validation.url_invalid', requireTld: true)]
    public ?string $seoCanonicalUrl = null;

    public bool $seoNoindex = false;

    /**
     * When true, Ai (if loaded) publishes siblings in the other active locales.
     * The original is flushed first; a failed translation never rolls it back.
     */
    public bool $autoTranslate = false;

    /**
     * Selected category ids; first becomes the primary Node::$category.
     *
     * @var list<int>
     */
    public array $categoryIds = [];

    /**
     * Comma-separated tags resolved via TagRepository::findOrCreateByNames().
     */
    public ?string $tags = null;

    public ?int $featuredImageAssetId = null;

    /**
     * One of PostSubType values; Assert\Choice uses PostSubType::values().
     */
    #[Assert\Choice(callback: [PostSubType::class, 'values'], message: 'blog.validation.sub_type_invalid')]
    public string $postSubType = PostSubType::ARTICLE;

    /**
     * Meaningful for PROJECT; other kinds ignore this in mapDtoToNode().
     */
    #[Assert\Length(max: 255, maxMessage: 'blog.validation.repo_url_max')]
    #[Assert\Url(message: 'blog.validation.url_invalid', requireTld: true)]
    public ?string $projectRepoUrl = null;

    #[Assert\Length(max: 255, maxMessage: 'blog.validation.demo_url_max')]
    #[Assert\Url(message: 'blog.validation.url_invalid', requireTld: true)]
    public ?string $projectDemoUrl = null;

    #[Assert\Length(max: 50, maxMessage: 'blog.validation.version_max')]
    public ?string $softwareVersion = null;

    #[Assert\Length(max: 255, maxMessage: 'blog.validation.download_url_max')]
    #[Assert\Url(message: 'blog.validation.url_invalid', requireTld: true)]
    public ?string $softwareDownloadUrl = null;

    /**
     * Plain-text code snippet for NOTE kind (never HTML / |raw).
     */
    public ?string $noteCodeSnippet = null;

    /**
     * Runtime required-field checks that depend on postSubType (single Callback).
     */
    public function validatePostSubTypeFields(ExecutionContextInterface $context): void
    {
        match ($this->postSubType) {
            PostSubType::PROJECT => $this->validateProjectFields($context),
            PostSubType::SOFTWARE => $this->validateSoftwareFields($context),
            PostSubType::NOTE => $this->validateNoteFields($context),
            default => null,
        };
    }

    private function validateProjectFields(ExecutionContextInterface $context): void
    {
        if (trim((string) $this->projectRepoUrl) === '') {
            $context->buildViolation('blog.validation.project_repo_required')
                ->atPath('projectRepoUrl')
                ->addViolation();
        }

        if (trim((string) $this->projectDemoUrl) === '') {
            $context->buildViolation('blog.validation.project_demo_required')
                ->atPath('projectDemoUrl')
                ->addViolation();
        }
    }

    private function validateSoftwareFields(ExecutionContextInterface $context): void
    {
        if (trim((string) $this->softwareVersion) === '') {
            $context->buildViolation('blog.validation.software_version_required')
                ->atPath('softwareVersion')
                ->addViolation();
        }
    }

    private function validateNoteFields(ExecutionContextInterface $context): void
    {
        if (trim((string) $this->noteCodeSnippet) === '') {
            $context->buildViolation('blog.validation.note_code_required')
                ->atPath('noteCodeSnippet')
                ->addViolation();
        }
    }
}
