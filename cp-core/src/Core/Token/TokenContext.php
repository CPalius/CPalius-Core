<?php

declare(strict_types=1);

namespace App\Core\Token;

use App\Core\Taxonomy\Entity\Term;
use App\Entity\Node;
use App\Entity\User;

/**
 * Builds the $context map TokenReplacer expects from a primary subject.
 *
 * Drupal Token's `[node:author:mail]` chaining is a combinatorial explosion
 * (every module's hook_tokens() re-enters for every nested property). CPalius
 * instead injects related subjects as first-class types: a Node contributes
 * `node` AND its author as `user`, so `[user:mail]` works in a node pattern
 * without a second lookup language. Site tokens need no subject at all.
 */
final class TokenContext
{
    /**
     * @return array<string, mixed>
     */
    public static function for(mixed $subject): array
    {
        if ($subject instanceof Node) {
            $context = ['node' => $subject];
            $author = $subject->getAuthor();
            if ($author instanceof User) {
                $context['user'] = $author;
            }

            return $context;
        }

        if ($subject instanceof User) {
            return ['user' => $subject];
        }

        if ($subject instanceof Term) {
            return ['term' => $subject];
        }

        return [];
    }
}
