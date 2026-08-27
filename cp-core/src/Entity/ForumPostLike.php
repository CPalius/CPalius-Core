<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\ForumPostLikeRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Mesaj beğenisi — bir kullanıcı bir mesajı en fazla bir kez beğenebilir
 * (uniq_forum_post_like). Cotonti'de karşılığı yok; bu projeye özgü bir
 * etkileşim özelliği.
 */
#[ORM\Entity(repositoryClass: ForumPostLikeRepository::class)]
#[ORM\Table(name: 'forum_post_likes')]
#[ORM\UniqueConstraint(name: 'uniq_forum_post_like', columns: ['post_id', 'user_id'])]
class ForumPostLike
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
