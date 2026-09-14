<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Core\Workflow\WorkflowRegistry;
use App\Kernel;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

/**
 * The shipped editorial.yaml loads through the real registry + cache layer.
 */
#[CoversNothing]
final class WorkflowRegistryTest extends TestCase
{
    private ?Kernel $kernel = null;

    protected function tearDown(): void
    {
        $this->kernel?->shutdown();
        $this->kernel = null;
    }

    public function testEditorialWorkflowIsAvailable(): void
    {
        $this->kernel = new Kernel('test', true);
        $this->kernel->boot();
        /** @var WorkflowRegistry $registry */
        $registry = $this->kernel->getContainer()->get('test.service_container')->get(WorkflowRegistry::class);

        self::assertTrue($registry->has('editorial'));
        $editorial = $registry->get('editorial');
        self::assertNotNull($editorial);
        self::assertSame('draft', $editorial->initialPlace);
        self::assertContains('published', $editorial->places);
        self::assertNotNull($editorial->getTransition('approve'));
        self::assertSame('published', $editorial->getTransition('approve')?->to);
    }
}
