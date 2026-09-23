<?php

declare(strict_types=1);

namespace Modules\Forum\Entity;

use Doctrine\ORM\Mapping as ORM;
use Modules\Forum\Repository\ForumSmilieRepository;

/**
 * One smilie / emoji the composer can insert and the post body can render.
 *
 * XenForo (and every other forum) stores the trigger in the post — ":)" —
 * and turns it into a picture at display time. Keeping the same shape means
 * an imported board's shortcodes light up without rewriting every post.
 */
#[ORM\Entity(repositoryClass: ForumSmilieRepository::class)]
#[ORM\Table(name: 'cp_forum_smilies')]
#[ORM\UniqueConstraint(name: 'uniq_forum_smilie_code', columns: ['code'])]
#[ORM\Index(columns: ['sort_order'], name: 'idx_forum_smilie_sort')]
class ForumSmilie
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(type: 'string', length: 64)]
    private string $code;

    /** @var list<string> */
    #[ORM\Column(name: 'extra_codes', type: 'json')]
    private array $extraCodes = [];

    #[ORM\Column(type: 'string', length: 128)]
    private string $title;

    #[ORM\Column(name: 'image_url', type: 'string', length: 2048, nullable: true)]
    private ?string $imageUrl = null;

    #[ORM\Column(type: 'string', length: 16, nullable: true)]
    private ?string $emoji = null;

    #[ORM\Column(type: 'string', length: 64)]
    private string $category = 'default';

    #[ORM\Column(name: 'sort_order', type: 'integer')]
    private int $sortOrder = 0;

    #[ORM\Column(name: 'display_in_editor', type: 'boolean')]
    private bool $displayInEditor = true;

    #[ORM\Column(name: 'imported_from', type: 'string', length: 32, nullable: true)]
    private ?string $importedFrom = null;

    public function __construct(string $code, string $title)
    {
        $this->code = $code;
        $this->title = $title;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCode(): string
    {
        return $this->code;
    }

    public function setCode(string $code): static
    {
        $this->code = $code;

        return $this;
    }

    /**
     * @return list<string>
     */
    public function getExtraCodes(): array
    {
        return $this->extraCodes;
    }

    /**
     * @param list<string> $extraCodes
     */
    public function setExtraCodes(array $extraCodes): static
    {
        $this->extraCodes = array_values(array_filter($extraCodes, static fn (string $c): bool => $c !== ''));

        return $this;
    }

    /**
     * @return list<string>
     */
    public function allCodes(): array
    {
        return array_values(array_unique([$this->code, ...$this->extraCodes]));
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function setTitle(string $title): static
    {
        $this->title = $title;

        return $this;
    }

    public function getImageUrl(): ?string
    {
        return $this->imageUrl;
    }

    public function setImageUrl(?string $imageUrl): static
    {
        $this->imageUrl = $imageUrl !== null && $imageUrl !== '' ? $imageUrl : null;

        return $this;
    }

    public function getEmoji(): ?string
    {
        return $this->emoji;
    }

    public function setEmoji(?string $emoji): static
    {
        $this->emoji = $emoji !== null && $emoji !== '' ? $emoji : null;

        return $this;
    }

    public function getCategory(): string
    {
        return $this->category;
    }

    public function setCategory(string $category): static
    {
        $this->category = $category !== '' ? $category : 'default';

        return $this;
    }

    public function getSortOrder(): int
    {
        return $this->sortOrder;
    }

    public function setSortOrder(int $sortOrder): static
    {
        $this->sortOrder = $sortOrder;

        return $this;
    }

    public function isDisplayInEditor(): bool
    {
        return $this->displayInEditor;
    }

    public function setDisplayInEditor(bool $displayInEditor): static
    {
        $this->displayInEditor = $displayInEditor;

        return $this;
    }

    public function getImportedFrom(): ?string
    {
        return $this->importedFrom;
    }

    public function setImportedFrom(?string $importedFrom): static
    {
        $this->importedFrom = $importedFrom;

        return $this;
    }
}
