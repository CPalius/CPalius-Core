<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Core\Entity\Query\CpEntityQueryFactory;
use App\Core\Security\EntityAccessManager;
use App\Entity\Node;
use App\Entity\User;
use App\Tests\Support\IntegrationTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * T1.4 — per-record access grants.
 *
 * Drupal solves this with node_access realms and gids: a second permission
 * model beside the first, kept correct by a rebuild step that silently yields
 * wrong answers when it is skipped. The design under test here keeps one
 * capability namespace, writes each grant so that it is independently correct
 * the moment it lands, and folds the lookup into the list query as a single
 * EXISTS — so granting one record to one user does not turn a list into N+1
 * voter calls.
 */
#[CoversClass(EntityAccessManager::class)]
final class EntityAccessGrantTest extends IntegrationTestCase
{
    public function testUserGrantReachesOnlyTheGrantedRecord(): void
    {
        [$posts, $member] = $this->seed();

        // "member" holds no node.post.* capability at all, so the baseline is
        // an empty list rather than a partial one.
        self::assertSame([], $this->visibleTitles($member));

        $this->grants()->grantToUser('node', (int) $posts['second']->getId(), 'node.post.view', (int) $member->getId());

        self::assertSame(['Second'], $this->visibleTitles($member), 'exactly the granted record, nothing else');

        // And the single-record check agrees with the list query — the two used
        // to disagree, which is worse than either being wrong alone.
        self::assertTrue($this->grants()->isGrantedForRecord('node', (int) $posts['second']->getId(), 'node.post.view', $member));
        self::assertFalse($this->grants()->isGrantedForRecord('node', (int) $posts['first']->getId(), 'node.post.view', $member));
    }

    public function testRoleGrantReachesEveryoneWithThatRole(): void
    {
        [$posts, $member] = $this->seed();

        $this->grants()->grantToRole('node', (int) $posts['first']->getId(), 'node.post.view', 'member');

        self::assertSame(['First'], $this->visibleTitles($member));

        // A second holder of the same role sees it too, without a second grant
        // row and without any rebuild.
        $other = $this->persistUser('other@example.test', ['member']);
        self::assertSame(['First'], $this->visibleTitles($other));

        // Someone outside the role does not.
        $stranger = $this->persistUser('stranger@example.test', ['guest']);
        self::assertSame([], $this->visibleTitles($stranger));
    }

    public function testAnyoneGrantAndUnknownCapabilityIsRefused(): void
    {
        [$posts, $member] = $this->seed();

        $this->grants()->grantToAnyone('node', (int) $posts['third']->getId(), 'node.post.view');
        self::assertSame(['Third'], $this->visibleTitles($member));

        // Fail-safe: a capability nobody registered cannot be granted. A typo
        // that produced a row would look like a working grant forever.
        $refused = $this->grants()->grantToAnyone('node', (int) $posts['first']->getId(), 'node.post.invented');
        self::assertNull($refused);
        self::assertCount(
            0,
            $this->grants()->grantsFor('node', (int) $posts['first']->getId()),
            'no row is written for an unknown capability',
        );

        // Revocation is immediate and equally rebuild-free.
        $this->grants()->revokeFromAnyone('node', (int) $posts['third']->getId(), 'node.post.view');
        self::assertSame([], $this->visibleTitles($member));
    }

    public function testExistingOwnershipScopeStillWorksAlongsideGrants(): void
    {
        [$posts, , $editor] = $this->seed();

        // "editor" carries node.post.view.own, so ownership alone is enough —
        // this is the path that existed before grants, and it must not change.
        self::assertSame(['First'], $this->visibleTitles($editor));

        // A grant on a record the editor does not own is additive: the two
        // conditions are OR'd, not replaced.
        $this->grants()->grantToUser('node', (int) $posts['third']->getId(), 'node.post.view', (int) $editor->getId());

        self::assertSame(['First', 'Third'], $this->visibleTitles($editor));
    }

    /**
     * Three posts: "First" owned by the editor, the others unowned.
     *
     * @return array{array<string, Node>, User, User}
     */
    private function seed(): array
    {
        $this->container();
        $em = $this->em();

        $editor = $this->persistUser('editor@example.test', ['editor']);
        $member = $this->persistUser('member@example.test', ['member']);

        $posts = [];
        foreach ([['first', 'First', $editor], ['second', 'Second', null], ['third', 'Third', null]] as [$slug, $title, $author]) {
            $node = new Node($title, $slug, 'post', 'en');
            $node->setAuthor($author);
            $node->publish();
            $em->persist($node);
            $posts[$slug] = $node;
        }

        $em->flush();

        return [$posts, $member, $editor];
    }

    /**
     * @param list<string> $roles
     */
    private function persistUser(string $email, array $roles): User
    {
        $user = new User($email);
        $user->setPassword('x')->setCpaliusRoles($roles)->setStatus(User::STATUS_ACTIVE);

        $em = $this->em();
        $em->persist($user);
        $em->flush();

        return $user;
    }

    /**
     * Titles the given user may see, through the list query rather than a
     * per-row voter call.
     *
     * @return list<string>
     */
    private function visibleTitles(User $user): array
    {
        $this->authenticate($user);

        /** @var CpEntityQueryFactory $factory */
        $factory = $this->container()->get(CpEntityQueryFactory::class);

        $titles = array_map(
            static fn (Node $node): string => $node->getTitle(),
            $factory->forBundle('post')->accessCheck('node.post.view')->sort('title', 'ASC')->getResult(),
        );

        return array_values($titles);
    }

    private function grants(): EntityAccessManager
    {
        /** @var EntityAccessManager $manager */
        $manager = $this->container()->get(EntityAccessManager::class);

        return $manager;
    }
}
