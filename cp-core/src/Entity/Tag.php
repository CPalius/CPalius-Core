<?php

namespace App\Entity;

use App\Core\Localization\Contract\TranslatableInterface;
use App\Core\Localization\Contract\TranslatableTrait;
use App\Repository\TagRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Category'den (cp-core/src/Entity/Category.php) BİLİNÇLİ OLARAK ayrı ve
 * hiyerarşisiz bir entity: kategoriler ağaç yapısı (parent/children)
 * taşırken etiketler düz bir liste olarak kalır — WordPress'teki
 * kategori/etiket ayrımıyla tutarlı.
 *
 * Generic tutulur (Blog'a özel bir "PostTag" DEĞİL): Node::tags ManyToMany
 * ilişkisi (bkz. Node.php) herhangi bir content type (post, portfolio vb.)
 * tarafından paylaşılabilir, kod tekrarını önler.
 */
#[ORM\Entity(repositoryClass: TagRepository::class)]
#[ORM\Table(name: 'tags')]
#[ORM\Index(columns: ['locale'], name: 'idx_tag_locale')]
#[ORM\UniqueConstraint(name: 'uniq_tag_translation_group_locale', columns: ['translation_group_id', 'locale'])]
#[ORM\UniqueConstraint(name: 'uniq_tag_slug_locale', columns: ['slug', 'locale'])]
class Tag implements TranslatableInterface
{
    // FAZ 3: translation_group_id kolonu + çeviri grubu yardımcıları.
    use TranslatableTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(type: 'string', length: 255)]
    private string $name;

    #[ORM\Column(type: 'string', length: 255)]
    private string $slug;

    #[ORM\Column(type: 'string', length: 5)]
    private string $locale;

    public function __construct(string $name, string $slug, string $locale)
    {
        $this->name = $name;
        $this->slug = $slug;
        $this->locale = $locale;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): static
    {
        $this->name = $name;

        return $this;
    }

    public function getSlug(): string
    {
        return $this->slug;
    }

    public function setSlug(string $slug): static
    {
        $this->slug = $slug;

        return $this;
    }

    public function getLocale(): string
    {
        return $this->locale;
    }

    public function setLocale(string $locale): static
    {
        $this->locale = $locale;

        return $this;
    }
}
