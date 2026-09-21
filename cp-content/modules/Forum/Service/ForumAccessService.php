<?php

declare(strict_types=1);

namespace Modules\Forum\Service;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;
use Modules\Forum\Entity\ForumBanFilter;
use Modules\Forum\Entity\ForumSection;
use Modules\Forum\Entity\ForumUserBlock;
use Modules\Forum\ForumBanFilterType;
use Modules\Forum\Repository\ForumBanFilterRepository;
use Modules\Forum\Repository\ForumUserBlockRepository;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\SessionInterface;

final class ForumAccessService
{
    private const GRANT_PREFIX = 'forum.access.grant.';

    /** @var list<ForumBanFilter>|null */
    private ?array $filters = null;

    /** @var array<int, list<int>> */
    private array $blockedIdsRuntime = [];

    public function __construct(
        private readonly ForumBanFilterRepository $banFilterRepository,
        private readonly ForumUserBlockRepository $userBlockRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly RequestStack $requestStack,
    ) {
    }

    public function matchingFilter(?string $ip, ?string $email = null, ?string $name = null): ?ForumBanFilter
    {
        foreach ($this->filters() as $filter) {
            $matched = match ($filter->getType()) {
                ForumBanFilterType::Ip => $ip !== null && $ip !== '' && $this->ipMatches($ip, $filter->getRule()),
                ForumBanFilterType::Email => $email !== null && $email !== '' && $this->wildcardMatches($email, $filter->getRule()),
                ForumBanFilterType::Name => $name !== null && $name !== '' && $this->wildcardMatches($name, $filter->getRule()),
            };
            if ($matched) {
                return $filter;
            }
        }

        return null;
    }

    public function matchingFilterForUser(?User $user, ?string $ip): ?ForumBanFilter
    {
        $email = $user?->getEmail();
        $name = $user?->getUsername() ?: $user?->getPublicDisplayName();

        return $this->matchingFilter($ip, $email, $name !== '' ? $name : null);
    }

    public function assertNotFiltered(?User $user, ?string $ip): void
    {
        if ($this->matchingFilterForUser($user, $ip) !== null) {
            throw new \DomainException('forum.error.ban_filter_matched');
        }
    }

    public function lockedAncestor(ForumSection $section): ?ForumSection
    {
        $cursor = $section;
        $guard = 0;
        while ($cursor instanceof ForumSection && $guard++ < 32) {
            if ($cursor->isPassworded() && !$this->sessionHasGrant($cursor)) {
                return $cursor;
            }
            $cursor = $cursor->getParent();
        }

        return null;
    }

    public function hasSectionAccess(ForumSection $section): bool
    {
        return $this->lockedAncestor($section) === null;
    }

    public function unlockSection(ForumSection $section, string $secret): void
    {
        $hash = $section->getAccessSecretHash();
        if ($hash === null || $hash === '') {
            return;
        }

        if (!password_verify($secret, $hash)) {
            throw new \DomainException('forum.error.section_secret_invalid');
        }

        $session = $this->session();
        if ($session === null) {
            throw new \DomainException('forum.error.section_access_denied');
        }

        $session->set($this->grantKey($section), true);
    }

    public function hashSectionSecret(string $secret): string
    {
        return password_hash($secret, \PASSWORD_DEFAULT);
    }

    public function block(User $user, User $blocked): ForumUserBlock
    {
        if ($user->getId() !== null && $user->getId() === $blocked->getId()) {
            throw new \DomainException('forum.error.cannot_block_self');
        }

        $existing = $this->userBlockRepository->findOne($user, $blocked);
        if ($existing instanceof ForumUserBlock) {
            return $existing;
        }

        $row = new ForumUserBlock($user, $blocked);
        $this->entityManager->persist($row);
        $this->entityManager->flush();
        unset($this->blockedIdsRuntime[$user->getId() ?? 0]);

        return $row;
    }

    public function unblock(User $user, User $blocked): void
    {
        $existing = $this->userBlockRepository->findOne($user, $blocked);
        if (!$existing instanceof ForumUserBlock) {
            return;
        }

        $this->entityManager->remove($existing);
        $this->entityManager->flush();
        unset($this->blockedIdsRuntime[$user->getId() ?? 0]);
    }

    public function isBlocked(User $user, User $other): bool
    {
        return \in_array($other->getId(), $this->blockedIds($user), true);
    }

    /**
     * @return list<int>
     */
    public function blockedIds(User $user): array
    {
        $userId = $user->getId() ?? 0;
        if (isset($this->blockedIdsRuntime[$userId])) {
            return $this->blockedIdsRuntime[$userId];
        }

        $ids = [];
        foreach ($this->userBlockRepository->findForUser($user) as $row) {
            $blockedId = $row->getBlocked()->getId();
            if ($blockedId !== null) {
                $ids[] = $blockedId;
            }
        }

        return $this->blockedIdsRuntime[$userId] = $ids;
    }

    public function applyIgnoredAuthors(QueryBuilder $qb, string $authorExpr, ?User $viewer, string $param = 'ignoredAuthorIds'): void
    {
        if ($viewer === null) {
            return;
        }

        $ids = $this->blockedIds($viewer);
        if ($ids === []) {
            return;
        }

        $qb->andWhere('('.$authorExpr.' IS NULL OR IDENTITY('.$authorExpr.') NOT IN (:'.$param.'))')
            ->setParameter($param, $ids);
    }

    /**
     * @return list<ForumBanFilter>
     */
    private function filters(): array
    {
        return $this->filters ??= $this->banFilterRepository->findBy([], ['id' => 'ASC']);
    }

    private function ipMatches(string $ip, string $rule): bool
    {
        if (str_contains($rule, '/')) {
            return $this->cidrContains($ip, $rule);
        }

        return $this->wildcardMatches($ip, $rule);
    }

    private function cidrContains(string $ip, string $cidr): bool
    {
        $parts = explode('/', $cidr, 2);
        if (\count($parts) !== 2) {
            return false;
        }

        [$subnet, $bitsRaw] = $parts;
        $ipBin = inet_pton($ip);
        $subBin = inet_pton($subnet);
        if ($ipBin === false || $subBin === false || \strlen($ipBin) !== \strlen($subBin)) {
            return false;
        }

        $maxBits = \strlen($ipBin) * 8;
        $bits = max(0, min($maxBits, (int) $bitsRaw));
        $full = intdiv($bits, 8);
        $rest = $bits % 8;
        if ($full > 0 && substr($ipBin, 0, $full) !== substr($subBin, 0, $full)) {
            return false;
        }
        if ($rest === 0) {
            return true;
        }

        $mask = (0xFF << (8 - $rest)) & 0xFF;

        return (ord($ipBin[$full]) & $mask) === (ord($subBin[$full]) & $mask);
    }

    private function wildcardMatches(string $value, string $rule): bool
    {
        $value = mb_strtolower(trim($value));
        $rule = mb_strtolower(trim($rule));
        if (!str_contains($rule, '*') && !str_contains($rule, '?')) {
            return $value === $rule;
        }

        $regex = '/^'.str_replace(['\\*', '\\?'], ['.*', '.'], preg_quote($rule, '/')).'$/u';

        return preg_match($regex, $value) === 1;
    }

    private function sessionHasGrant(ForumSection $section): bool
    {
        return $this->session()?->get($this->grantKey($section)) === true;
    }

    private function grantKey(ForumSection $section): string
    {
        return self::GRANT_PREFIX.(string) ($section->getId() ?? 0);
    }

    private function session(): ?SessionInterface
    {
        $request = $this->requestStack->getCurrentRequest();
        if ($request === null || !$request->hasSession()) {
            return null;
        }

        return $request->getSession();
    }
}
