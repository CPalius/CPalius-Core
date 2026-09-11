<?php

declare(strict_types=1);

namespace Modules\Forum\Event;

use App\Entity\User;
use Modules\Forum\Entity\ForumPost;
use Symfony\Contracts\EventDispatcher\Event;

/** Dispatched when a post is liked (not on unlike). */
final class ForumPostLikedEvent extends Event
{
    public const NAME = 'forum.post.liked';

    public function __construct(
        private readonly ForumPost $post,
        private readonly User $liker,
        private readonly User $postAuthor,
    ) {
    }

    public function getPost(): ForumPost
    {
        return $this->post;
    }

    public function getLiker(): User
    {
        return $this->liker;
    }

    public function getPostAuthor(): User
    {
        return $this->postAuthor;
    }
}
