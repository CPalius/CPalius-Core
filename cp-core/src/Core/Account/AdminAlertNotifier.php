<?php

declare(strict_types=1);

namespace App\Core\Account;

use App\Core\Notification\NotificationDispatcher;
use App\Core\Security\UserRoleGuardService;
use App\Entity\User;
use App\Repository\UserRepository;
use Psr\Log\LoggerInterface;

/**
 * Tells every administrator that something needs a decision.
 *
 * A request that waits in a queue nobody looks at is a request nobody answers: a member asking to change
 * a username could wait for months. This puts the request in each administrator's notification inbox
 * (the AACP/Studio bell) at the moment it is filed. Core types only; modules that have their own approval
 * queues call it with their own event key.
 *
 * Never throws: the member's action has already succeeded, and a notification failure must not turn it
 * into an error page.
 */
final class AdminAlertNotifier
{
    public function __construct(
        private readonly NotificationDispatcher $dispatcher,
        private readonly UserRepository $users,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @param array<string, mixed> $payload event-specific params; "url" is where the bell sends the click
     */
    public function notifyAdmins(string $eventKey, array $payload, ?User $actor = null, ?string $dedupeKey = null): void
    {
        try {
            foreach ($this->users->findByRole(UserRoleGuardService::ROLE_ADMIN) as $admin) {
                $this->dispatcher->dispatch($eventKey, $admin, $payload, $actor, null, $dedupeKey);
            }
        } catch (\Throwable $e) {
            $this->logger->error('Admin alert failed.', ['event' => $eventKey, 'exception' => $e->getMessage()]);
        }
    }
}
