<?php

declare(strict_types=1);

namespace Modules\Forum\Event;

use App\Entity\User;
use Modules\Forum\Entity\ForumPost;
use Symfony\Contracts\EventDispatcher\Event;

/** Dispatched when a post is disliked. */
final class ForumPostDislikedEvent extends Event
{
    public const NAME = 'forum.post.disliked';

    public function __construct(
        private readonly ForumPost $post,
        private readonly User $disliker,
        private readonly User $postAuthor,
    ) {
    }

    public function getPost(): ForumPost
    {
        return $this->post;
    }

    public function getDisliker(): User
    {
        return $this->disliker;
    }

    public function getPostAuthor(): User
    {
        return $this->postAuthor;
    }
}
