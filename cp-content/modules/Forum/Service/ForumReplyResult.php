<?php

declare(strict_types=1);

namespace Modules\Forum\Service;

use Modules\Forum\Entity\ForumPost;

/**
 * What happened to a submitted reply.
 *
 * A reply no longer maps one-to-one onto a row: anti-bump folds a consecutive
 * self-reply into the post above it. The caller has to be able to tell the two
 * apart — the flash message and the redirect target differ — and a bare
 * ForumPost cannot say which one it is.
 */
final readonly class ForumReplyResult
{
    public function __construct(
        public ForumPost $post,
        public bool $merged,
    ) {
    }

    public static function created(ForumPost $post): self
    {
        return new self($post, false);
    }

    public static function mergedInto(ForumPost $post): self
    {
        return new self($post, true);
    }
}
