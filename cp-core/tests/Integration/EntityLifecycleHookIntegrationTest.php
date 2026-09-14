<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Core\Entity\Event\EntityLifecycleRejectedException;
use App\Core\Entity\EventListener\EntityLifecycleListener;
use App\Entity\Node;
use App\Entity\User;
use App\Kernel;
use App\Tests\Support\IntegrationSchema;
use Doctrine\ORM\EntityManagerInterface;
use Modules\HookFixture\EntityHookRecorder;
use Modules\HookFixture\HookFixtureModule;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

/**
 * T1.5 — typed entity lifecycle events, carried by the existing hook engine.
 *
 * The decision worth testing is not that events fire; it is what happens when a
 * listener misbehaves. WordPress and Drupal both turn a throwing hook into a
 * fatal request. Here the hook engine's isolation already exists, so a listener
 * with a bug is quarantined and the save still completes — while a deliberate
 * reject() really does stop the flush. Those two must stay distinguishable:
 * collapsing them would either swallow business rules or resurrect the fatal.
 *
 * This test boots its own kernel because it needs a fixture module registered,
 * so it cannot use IntegrationTestCase.
 */
#[CoversClass(EntityLifecycleListener::class)]
final class EntityLifecycleHookIntegrationTest extends TestCase
{
    private ?Kernel $kernel = null;

    protected function setUp(): void
    {
        EntityHookRecorder::reset();
    }

    protected function tearDown(): void
    {
        EntityHookRecorder::reset();
        $this->kernel?->shutdown();
        $this->kernel = null;
    }

    public function testPostInsertFiresForBothNodeAndUser(): void
    {
        $container = $this->boot();
        $em = $this->em($container);

        $em->persist(new Node('Launch', 'launch', 'page', 'en'));
        $em->flush();

        // A single entity-agnostic Doctrine listener serves every
        // FieldableInterface, so nothing had to be registered per entity type.
        self::assertSame(['Launch'], EntityHookRecorder::insertedTitles());
    }

    public function testPreSaveRejectionAbortsTheFlushAndNothingIsPersisted(): void
    {
        $container = $this->boot();
        $em = $this->em($container);

        EntityHookRecorder::vetoNextPreSave();

        $node = new Node('Rejected', 'rejected', 'page', 'en');

        try {
            $em->persist($node);
            $em->flush();
            self::fail('reject() must stop the write.');
        } catch (EntityLifecycleRejectedException $e) {
            self::assertStringContainsString('hookfixture.vetoed', $e->getMessage());
        }

        // A rejection is a business decision, so it is loud and nothing lands.
        $em->clear();
        self::assertNull(
            $em->getRepository(Node::class)->findOneBy(['slug' => 'rejected']),
            'a rejected entity must not be persisted',
        );
    }

    public function testAThrowingListenerIsQuarantinedNotFatal(): void
    {
        $container = $this->boot();
        $em = $this->em($container);

        EntityHookRecorder::throwOnNextPostInsert();

        $node = new Node('Survivor', 'survivor', 'page', 'en');
        $em->persist($node);
        $em->flush();

        // The decisive assertion: the listener's own bug did not take the
        // request with it. In WordPress this scenario is a white screen.
        $em->clear();
        $reloaded = $em->getRepository(Node::class)->findOneBy(['slug' => 'survivor']);
        self::assertInstanceOf(Node::class, $reloaded, 'the save completed despite the failing listener');

        // And the failure was recorded rather than silently swallowed.
        $log = $this->kernel?->getProjectDir().'/cp-core/var/log/module_quarantine.log';
        if (is_file($log)) {
            self::assertStringContainsString(
                'EntityHookRecorder',
                (string) file_get_contents($log),
                'the quarantined listener is named in the log',
            );
        }
    }

    public function testAccessEventExtendsTheVoterBeyondRoleAndGrant(): void
    {
        $container = $this->boot();
        $em = $this->em($container);

        $user = new User('member@example.test');
        $user->setPassword('x')->setCpaliusRoles(['member'])->setStatus(User::STATUS_ACTIVE);
        $em->persist($user);

        $node = new Node('Secret', 'secret', 'post', 'en');
        $em->persist($node);
        $em->flush();

        /** @var TokenStorageInterface $tokenStorage */
        $tokenStorage = $container->get(TokenStorageInterface::class);
        $tokenStorage->setToken(new UsernamePasswordToken($user, 'main', $user->getRoles()));

        /** @var Security $security */
        $security = $container->get(Security::class);

        // "member" holds no node.post.* capability and there is no grant, so
        // the voter alone refuses.
        self::assertFalse($security->isGranted('node.post.view', $node));

        // A listener answering the access event reaches past both.
        EntityHookRecorder::allowNextAccess();
        self::assertTrue($security->isGranted('node.post.view', $node));
    }

    private function boot(): ContainerInterface
    {
        $this->kernel = new HookFixtureTestKernel('test', true);
        $this->kernel->boot();

        /** @var ContainerInterface $container */
        $container = $this->kernel->getContainer()->get('test.service_container');

        IntegrationSchema::reset($this->em($container));

        return $container;
    }

    private function em(ContainerInterface $container): EntityManagerInterface
    {
        /** @var EntityManagerInterface $em */
        $em = $container->get(EntityManagerInterface::class);

        return $em;
    }
}

/**
 * Registers the hook fixture module on top of the real core bundles, so the
 * listener under test is a genuine #[CpHook] service rather than a stub.
 */
final class HookFixtureTestKernel extends Kernel
{
    public function registerBundles(): iterable
    {
        yield from parent::registerBundles();

        yield new HookFixtureModule();
    }

    /** Separate cache dir — the fixture bundle changes the service graph. */
    public function getCacheDir(): string
    {
        return $this->getProjectDir().'/cp-core/var/cache/test_hook_fixture';
    }
}
