<?php

declare(strict_types=1);

namespace Modules\Messages\Service;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Modules\Messages\Entity\MessageBlock;
use Modules\Messages\Repository\MessageBlockRepository;

final class MessagesBlockService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly MessageBlockRepository $blocks,
        private readonly MessagesAccess $access,
    ) {
    }

    public function block(User $actor, User $target, ?string $reason = null): MessageBlock
    {
        if (!$this->access->canBlock()) {
            throw new MessagesDeniedException('messages.error.no_permission');
        }

        if ($actor->getId() === $target->getId()) {
            throw new MessagesDeniedException('messages.error.self');
        }

        $existing = $this->blocks->findBetween($actor, $target);
        if ($existing instanceof MessageBlock) {
            return $existing;
        }

        $block = new MessageBlock($actor, $target, $reason);
        $this->entityManager->persist($block);
        $this->entityManager->flush();

        return $block;
    }

    public function unblock(User $actor, User $target): void
    {
        $existing = $this->blocks->findBetween($actor, $target);
        if ($existing === null) {
            return;
        }

        $this->entityManager->remove($existing);
        $this->entityManager->flush();
    }
}
