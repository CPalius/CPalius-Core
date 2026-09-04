<?php

declare(strict_types=1);

namespace Modules\Forum\Service;

use App\Entity\User;
use App\Repository\UserRepository;

/**
 * Extract @username mentions from a post body.
 */
final class ForumMentionParser
{
    public function __construct(
        private readonly UserRepository $userRepository,
    ) {
    }

    /**
     * @return list<User>
     */
    public function extractMentionedUsers(string $bodyHtml): array
    {
        $text = html_entity_decode(strip_tags($bodyHtml), \ENT_QUOTES | \ENT_HTML5, 'UTF-8');
        if ($text === '') {
            return [];
        }

        if (!preg_match_all('/@([a-zA-Z0-9_\-.]{2,32})/u', $text, $matches)) {
            return [];
        }

        $users = [];
        $seen = [];

        foreach ($matches[1] as $username) {
            $key = mb_strtolower($username);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;

            $user = $this->userRepository->findOneByUsername($username);
            if ($user instanceof User) {
                $users[] = $user;
            }
        }

        return $users;
    }
}
