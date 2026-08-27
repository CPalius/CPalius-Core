<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\ForumPostDislikeRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Mesaj beğenmemesi — bir kullanıcı bir mesajı en fazla bir kez beğenmeyebilir
 * (uniq_forum_post_dislike). Beğeni ile karşılıklı dışlayan etkileşim.
 */
#[ORM\Entity(repositoryClass: ForumPostDislikeRepository::class)]
#[ORM\Table(name: 'forum_post_dislikes')]
#[ORM\UniqueConstraint(name: 'uniq_forum_post_dislike', columns: ['post_id', 'user_id'])]
class ForumPostDislike
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: ForumPost::class)]
    #[ORM\JoinColumn(name: 'post_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private ForumPost $post;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'user_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    public function __construct(ForumPost $post, User $user)
    {
        $this->post = $post;
        $this->user = $user;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getPost(): ForumPost
    {
        return $this->post;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
