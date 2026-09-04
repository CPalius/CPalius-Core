<?php

declare(strict_types=1);

namespace Modules\Blog\Form\DTO;

use App\Entity\Node;
use Modules\Blog\PostSubType;
use Symfony\Component\Validator\Context\ExecutionContextInterface;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Form-layer DTO for post CRUD; not a Doctrine entity (Law 3.1). Body is sanitized in mapDtoToNode().
 * noteCodeSnippet stays plain text (never |raw / RichTextSanitizer).
 */
#[Assert\Callback('validatePostSubTypeFields')]
final class PostFormModel
{
    #[Assert\NotBlank(message: 'Başlık zorunludur.')]
    #[Assert\Length(max: 255, maxMessage: 'Başlık en fazla {{ limit }} karakter olabilir.')]
    public string $title = '';

    /**
     * Optional; empty slug is generated from title in PostAdminController.
     */
    #[Assert\Length(max: 255, maxMessage: 'Slug en fazla {{ limit }} karakter olabilir.')]
    #[Assert\Regex(
        pattern: '/^[a-z0-9]+(?:-[a-z0-9]+)*$/',
        message: 'Slug yalnızca küçük harf, rakam ve tire (-) içerebilir.',
        match: true,
    )]
    public ?string $slug = null;

    #[Assert\Length(max: 500, maxMessage: 'Özet en fazla {{ limit }} karakter olabilir.')]
    public ?string $excerpt = null;

    #[Assert\NotBlank(message: 'İçerik zorunludur.')]
    public string $body = '';

    public bool $isFeatured = false;

    /**
     * Raw Studio status; resolveEffectiveStatus() may coerce published+future to scheduled.
     */
    #[Assert\Choice(
        choices: [Node::STATUS_DRAFT, Node::STATUS_PUBLISHED, Node::STATUS_SCHEDULED],
        message: 'Geçersiz yayın durumu.',
    )]
    public string $status = Node::STATUS_DRAFT;

    /**
     * Studio publish-at; empty + published becomes now in resolveEffectiveStatus().
     */
    public ?\DateTimeImmutable $publishedAt = null;

    #[Assert\Length(max: 160, maxMessage: 'Meta açıklama en fazla {{ limit }} karakter olabilir.')]
    public ?string $seoMetaDescription = null;

    #[Assert\Length(max: 255, maxMessage: 'Odak anahtar kelime en fazla {{ limit }} karakter olabilir.')]
    public ?string $seoFocusKeyword = null;

    #[Assert\Length(max: 255, maxMessage: 'Canonical URL en fazla {{ limit }} karakter olabilir.')]
    #[Assert\Url(message: 'Geçerli bir URL girin.', requireTld: true)]
    public ?string $seoCanonicalUrl = null;

    public bool $seoNoindex = false;

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
    #[Assert\Choice(callback: [PostSubType::class, 'values'], message: 'Geçersiz içerik türü.')]
    public string $postSubType = PostSubType::ARTICLE;

    /**
     * Meaningful for PROJECT; other kinds ignore this in mapDtoToNode().
     */
    #[Assert\Length(max: 255, maxMessage: 'Depo URL\'si en fazla {{ limit }} karakter olabilir.')]
    #[Assert\Url(message: 'Geçerli bir URL girin.', requireTld: true)]
    public ?string $projectRepoUrl = null;

    #[Assert\Length(max: 255, maxMessage: 'Demo URL\'si en fazla {{ limit }} karakter olabilir.')]
    #[Assert\Url(message: 'Geçerli bir URL girin.', requireTld: true)]
    public ?string $projectDemoUrl = null;

    #[Assert\Length(max: 50, maxMessage: 'Versiyon en fazla {{ limit }} karakter olabilir.')]
    public ?string $softwareVersion = null;

    #[Assert\Length(max: 255, maxMessage: 'İndirme URL\'si en fazla {{ limit }} karakter olabilir.')]
    #[Assert\Url(message: 'Geçerli bir URL girin.', requireTld: true)]
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
            $context->buildViolation('Proje türü için depo (GitHub) URL\'si zorunludur.')
                ->atPath('projectRepoUrl')
                ->addViolation();
        }

        if (trim((string) $this->projectDemoUrl) === '') {
            $context->buildViolation('Proje türü için demo URL\'si zorunludur.')
                ->atPath('projectDemoUrl')
                ->addViolation();
        }
    }

    private function validateSoftwareFields(ExecutionContextInterface $context): void
    {
        if (trim((string) $this->softwareVersion) === '') {
            $context->buildViolation('Yazılım türü için versiyon bilgisi zorunludur.')
                ->atPath('softwareVersion')
                ->addViolation();
        }
    }

    private function validateNoteFields(ExecutionContextInterface $context): void
    {
        if (trim((string) $this->noteCodeSnippet) === '') {
            $context->buildViolation('Not türü için kod paylaşım alanı zorunludur.')
                ->atPath('noteCodeSnippet')
                ->addViolation();
        }
    }
}
