<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Core\Entity\EntityTypeRegistry;
use App\Entity\Node;
use App\Entity\User;
use App\Kernel;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

/**
 * EntityTypeRegistrationPass really scans #[CpEntityType] across the source tree
 * and fills EntityTypeRegistry in the compiled container.
 */
#[CoversNothing]
final class EntityTypeRegistrationTest extends TestCase
{
    private ?Kernel $kernel = null;

    protected function tearDown(): void
    {
        $this->kernel?->shutdown();
        $this->kernel = null;
    }

    public function testCoreEntityTypesAreDiscovered(): void
    {
        $this->kernel = new Kernel('test', true);
        $this->kernel->boot();
        $container = $this->kernel->getContainer()->get('test.service_container');

        /** @var EntityTypeRegistry $registry */
        $registry = $container->get(EntityTypeRegistry::class);

        self::assertTrue($registry->has('node'));
        self::assertTrue($registry->has('user'));

        $node = $registry->get('node');
        self::assertSame(Node::class, $node->className);
        self::assertTrue($node->fieldable);
        self::assertTrue($node->bundleable);

        $user = $registry->get('user');
        self::assertSame(User::class, $user->className);
        self::assertTrue($user->fieldable);
        self::assertFalse($user->bundleable);

        self::assertArrayHasKey('user', $registry->fieldable());
        self::assertSame('node', $registry->forClass(Node::class)?->id);
    }
}
