<?php

declare(strict_types=1);

namespace Modules\Widget\Entity;

use Doctrine\ORM\Mapping as ORM;
use Modules\Widget\Repository\WidgetInstanceRepository;

/**
 * One widget placed in an area. `type` names a WidgetTypeInterface; `settings` are that type's own
 * values, `visibility` the common rules (pages, audience, CSS class) every widget shares.
 */
#[ORM\Entity(repositoryClass: WidgetInstanceRepository::class)]
#[ORM\Table(name: 'cp_widgets')]
#[ORM\Index(columns: ['area', 'position'], name: 'idx_widget_area_position')]
class WidgetInstance
{
    public const AUDIENCE_ALL = 'all';
    public const AUDIENCE_GUESTS = 'guests';
    public const AUDIENCE_MEMBERS = 'members';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(type: 'string', length: 64)]
    private string $area;

    #[ORM\Column(type: 'string', length: 100)]
    private string $type;

    #[ORM\Column(type: 'string', length: 255)]
    private string $title = '';

    /** @var array<string, mixed> */
    #[ORM\Column(type: 'json')]
    private array $settings = [];

    /** @var array{pages?: list<string>, audience?: string, css?: string} */
    #[ORM\Column(type: 'json')]
    private array $visibility = [];

    #[ORM\Column(type: 'string', length: 10, nullable: true)]
    private ?string $locale = null;

    #[ORM\Column(type: 'integer')]
    private int $position = 0;

    #[ORM\Column(name: 'is_active', type: 'boolean')]
    private bool $isActive = true;

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $updatedAt;

    public function __construct(string $area, string $type)
    {
        $this->area = $area;
        $this->type = $type;
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = $this->createdAt;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getArea(): string
    {
        return $this->area;
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function setTitle(string $title): static
    {
        $this->title = $title;

        return $this->touch();
    }

    /**
     * @return array<string, mixed>
     */
    public function getSettings(): array
    {
        return $this->settings;
    }

    /**
     * @param array<string, mixed> $settings
     */
    public function setSettings(array $settings): static
    {
        $this->settings = $settings;

        return $this->touch();
    }

    /**
     * The title the admin typed, per language. Kept inside the settings under a reserved key so no new
     * column is needed; empty means the title column (a translation key) applies. setSettings() replaces
     * the whole array, so call setTitles() after it.
     *
     * @return array<string, string>
     */
    public function getTitles(): array
    {
        $titles = $this->settings['_title'] ?? [];

        return \is_array($titles) ? array_filter($titles, static fn (mixed $t): bool => \is_string($t) && $t !== '') : [];
    }

    /**
     * @param array<string, string> $titles language => title; empty ones are dropped
     */
    public function setTitles(array $titles): static
    {
        $titles = array_filter($titles, static fn (string $t): bool => $t !== '');
        if ($titles === []) {
            unset($this->settings['_title']);
        } else {
            $this->settings['_title'] = $titles;
        }

        return $this->touch();
    }

    /**
     * @return list<string> page words the widget is limited to; empty = every page
     */
    public function getPages(): array
    {
        $pages = $this->visibility['pages'] ?? [];

        return \is_array($pages) ? array_values(array_filter($pages, 'is_string')) : [];
    }

    public function getAudience(): string
    {
        $audience = $this->visibility['audience'] ?? self::AUDIENCE_ALL;

        return \in_array($audience, [self::AUDIENCE_GUESTS, self::AUDIENCE_MEMBERS], true) ? $audience : self::AUDIENCE_ALL;
    }

    public function getCssClass(): string
    {
        return (string) ($this->visibility['css'] ?? '');
    }

    /**
     * @param list<string> $pages
     */
    public function setVisibility(array $pages, string $audience, string $css): static
    {
        // Merged, not replaced: the placement keys (extra areas, themes, schedule) live next to these.
        $this->visibility = ['pages' => $pages, 'audience' => $audience, 'css' => $css] + $this->visibility;

        return $this->touch();
    }

    /**
     * @return array{pages?: list<string>, audience?: string, css?: string}
     */
    /**
     * Other areas this same widget is also drawn in (one box, several modules).
     *
     * @return list<string>
     */
    public function getExtraAreas(): array
    {
        return $this->stringList('areas');
    }

    /**
     * Theme directory names the widget is limited to; empty = every theme.
     *
     * @return list<string>
     */
    public function getThemes(): array
    {
        return $this->stringList('themes');
    }

    /** "Y-m-d\TH:i" in the server time zone, or '' when there is no start. */
    public function getShowFrom(): string
    {
        return $this->stamp('from');
    }

    public function getShowUntil(): string
    {
        return $this->stamp('until');
    }

    /**
     * @param list<string> $areas
     * @param list<string> $themes
     */
    public function setPlacement(array $areas, array $themes, string $from, string $until): static
    {
        $this->visibility['areas'] = array_values(array_unique(array_filter($areas, fn (string $a): bool => $a !== $this->area)));
        $this->visibility['themes'] = array_values(array_unique($themes));
        $this->visibility['from'] = $from;
        $this->visibility['until'] = $until;

        return $this->touch();
    }

    public function drawnIn(string $area): bool
    {
        return $this->area === $area || \in_array($area, $this->getExtraAreas(), true);
    }

    public function getVisibility(): array
    {
        return $this->visibility;
    }

    public function getLocale(): ?string
    {
        return $this->locale;
    }

    public function setLocale(?string $locale): static
    {
        $this->locale = $locale !== null && $locale !== '' ? $locale : null;

        return $this->touch();
    }

    public function getPosition(): int
    {
        return $this->position;
    }

    public function setPosition(int $position): static
    {
        $this->position = $position;

        return $this->touch();
    }

    public function isActive(): bool
    {
        return $this->isActive;
    }

    public function setActive(bool $active): static
    {
        $this->isActive = $active;

        return $this->touch();
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    /** @return list<string> */
    private function stringList(string $key): array
    {
        $list = $this->visibility[$key] ?? [];

        return \is_array($list) ? array_values(array_filter($list, 'is_string')) : [];
    }

    private function stamp(string $key): string
    {
        $value = $this->visibility[$key] ?? '';

        return \is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/', $value) === 1 ? $value : '';
    }

    private function touch(): static
    {
        $this->updatedAt = new \DateTimeImmutable();

        return $this;
    }
}
