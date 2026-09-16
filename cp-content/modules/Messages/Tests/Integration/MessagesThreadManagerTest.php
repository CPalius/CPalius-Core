<?php

declare(strict_types=1);

namespace Modules\Messages\Tests\Integration;

use App\Entity\User;
use App\Tests\Support\IntegrationTestCase;
use Modules\Messages\Entity\Message;
use Modules\Messages\Entity\MessageThread;
use Modules\Messages\Service\MessagesContext;
use Modules\Messages\Service\MessagesDeniedException;
use Modules\Messages\Service\MessagesThreadManager;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Component\HttpFoundation\Request;

/**
 * The write path is the security boundary: two members can talk, you cannot
 * message yourself, and a second send about the same context reuses the thread.
 */
#[CoversClass(MessagesThreadManager::class)]
final class MessagesThreadManagerTest extends IntegrationTestCase
{
    public function testTwoMembersOpenOneThreadAndASecondSendReusesIt(): void
    {
        $alice = $this->authenticateAs('member', 'alice@example.test');
        $bob = $this->member('bob@example.test');

        $manager = $this->manager();
        $first = $manager->startOrContinue($alice, $bob, 'Hello Bob', 'restricted', $this->emptyContext());
        $second = $manager->startOrContinue($alice, $bob, 'Still there?', 'restricted', $this->emptyContext());

        self::assertSame($first->getThread()->getId(), $second->getThread()->getId());
        self::assertCount(2, $this->em()->getRepository(Message::class)->findAll());
        self::assertCount(1, $this->em()->getRepository(MessageThread::class)->findAll());
    }

    public function testAMemberCannotMessageThemselves(): void
    {
        $alice = $this->authenticateAs('member', 'solo@example.test');
        $manager = $this->manager();

        $this->expectException(MessagesDeniedException::class);
        $manager->startOrContinue($alice, $alice, 'nope', 'restricted', $this->emptyContext());
    }

    public function testDistinctContextsStayDistinctThreads(): void
    {
        $alice = $this->authenticateAs('member', 'ctx-a@example.test');
        $bob = $this->member('ctx-b@example.test');
        $manager = $this->manager();

        $listing = new Request(query: ['context_type' => 'showcase_item', 'context_id' => '7', 'context_label' => 'Car']);
        $profile = new Request(query: ['context_type' => 'forum_user', 'context_id' => (string) $bob->getId()]);

        $one = $manager->startOrContinue($alice, $bob, 'About the car', 'restricted', MessagesContext::fromRequest($listing));
        $two = $manager->startOrContinue($alice, $bob, 'Hi', 'restricted', MessagesContext::fromRequest($profile));

        self::assertNotSame($one->getThread()->getId(), $two->getThread()->getId());
        self::assertCount(2, $this->em()->getRepository(MessageThread::class)->findAll());
    }

    private function manager(): MessagesThreadManager
    {
        $this->pushRequest();

        /** @var MessagesThreadManager $manager */
        $manager = $this->container()->get(MessagesThreadManager::class);

        return $manager;
    }

    private function member(string $email): User
    {
        $user = new User($email);
        $user->setPassword('x')->setCpaliusRoles(['member'])->setStatus(User::STATUS_ACTIVE);
        $this->em()->persist($user);
        $this->em()->flush();

        return $user;
    }

    private function emptyContext(): MessagesContext
    {
        return MessagesContext::fromRequest(new Request());
    }
}
