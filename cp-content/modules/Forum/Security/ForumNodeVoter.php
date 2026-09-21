<?php

declare(strict_types=1);

namespace Modules\Forum\Security;

use App\Entity\User;
use Modules\Forum\Entity\ForumPost;
use Modules\Forum\Entity\ForumSection;
use Modules\Forum\Entity\ForumTopic;
use Modules\Forum\ForumPermission;
use Modules\Forum\Service\ForumPermissionService;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Board-level content permissions: is_granted('forum.node.view', $section).
 * Not registered as CPalius capabilities so CPaliusVoter abstains.
 */
final class ForumNodeVoter extends Voter
{
    private const PREFIX = 'forum.node.';

    public function __construct(
        private readonly ForumPermissionService $permissionService,
        #[Autowire(lazy: true)]
        private readonly AuthorizationCheckerInterface $authorizationChecker,
    ) {
    }

    protected function supports(string $attribute, mixed $subject): bool
    {
        if (!str_starts_with($attribute, self::PREFIX)) {
            return false;
        }

        $perm = $this->normalizePermission(substr($attribute, \strlen(self::PREFIX)));
        $wanted = ForumPermission::tryFrom($perm);

        return $wanted instanceof ForumPermission
            && $wanted->isContent()
            && ($subject instanceof ForumSection || $subject instanceof ForumTopic || $subject instanceof ForumPost);
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token): bool
    {
        $section = $this->resolveSection($subject);
        if ($section === null) {
            return false;
        }

        if ($this->authorizationChecker->isGranted('forum.section.manage')
            || $this->authorizationChecker->isGranted('forum.nodes.manage')
            || $this->authorizationChecker->isGranted('forum.topic.moderate')
        ) {
            return true;
        }

        $user = $token->getUser();
        $actor = $user instanceof User ? $user : null;
        $perm = $this->normalizePermission(substr($attribute, \strlen(self::PREFIX)));

        return $this->permissionService->isAllowed($section, $actor, $perm);
    }

    private function resolveSection(mixed $subject): ?ForumSection
    {
        if ($subject instanceof ForumSection) {
            return $subject;
        }
        if ($subject instanceof ForumTopic) {
            return $subject->getSection();
        }
        if ($subject instanceof ForumPost) {
            return $subject->getSection();
        }

        return null;
    }

    private function normalizePermission(string $permission): string
    {
        return $permission === 'poll' ? ForumPermission::PollCreate->value : $permission;
    }
}
