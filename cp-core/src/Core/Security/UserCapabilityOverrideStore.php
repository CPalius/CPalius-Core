<?php

declare(strict_types=1);

namespace App\Core\Security;

use App\Core\Security\Repository\UserCapabilityOverrideRepository;
use Psr\Log\LoggerInterface;

/**
 * One SELECT per user per request. The voter calls this on every is_granted;
 * memoising is what keeps that from becoming a query-per-check loop.
 *
 * A missing table (migration not applied) returns no overlays rather than
 * taking the site down — same fail-safe direction as a broken role YAML.
 */
final class UserCapabilityOverrideStore
{
    /** @var array<int, array<string, string>> */
    private array $memo = [];

    public function __construct(
        private readonly UserCapabilityOverrideRepository $overrides,
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    /**
     * @return array<string, string> capability => grant|deny
     */
    public function forUser(?int $userId): array
    {
        if ($userId === null) {
            return [];
        }

        if (isset($this->memo[$userId])) {
            return $this->memo[$userId];
        }

        try {
            $map = [];
            foreach ($this->overrides->findAllForUser($userId) as $row) {
                $map[$row->getCapability()] = $row->getEffect();
            }
        } catch (\Throwable $e) {
            $this->logger?->notice('User capability overlay could not be read.', ['exception' => $e]);
            $map = [];
        }

        return $this->memo[$userId] = $map;
    }

    public function forget(?int $userId): void
    {
        if ($userId !== null) {
            unset($this->memo[$userId]);
        }
    }
}
