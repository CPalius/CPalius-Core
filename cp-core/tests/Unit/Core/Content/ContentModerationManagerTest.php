<?php

declare(strict_types=1);

namespace App\Tests\Unit\Core\Content;

use App\Core\Content\ContentModerationManager;
use App\Core\Revision\RevisionManager;
use App\Core\Settings\SettingsRegistry;
use App\Core\Workflow\WorkflowDefinition;
use App\Core\Workflow\WorkflowManager;
use App\Core\Workflow\WorkflowRegistry;
use App\Core\Workflow\WorkflowTransition;
use App\Entity\Node;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ContentModerationManager::class)]
final class ContentModerationManagerTest extends TestCase
{
    private function definition(): WorkflowDefinition
    {
        return new WorkflowDefinition(
            'editorial',
            ['draft', 'review', 'published'],
            [
                'submit' => new WorkflowTransition('submit', ['draft'], 'review'),
                'approve' => new WorkflowTransition('approve', ['review'], 'published'),
            ],
            'draft',
            'moderation_state',
            true,
            [],
            ['published'],
        );
    }

    /**
     * @param array{apply?: array<string, mixed>} $workflowManagerStubs
     */
    private function manager(string $enabledTypes, array $applyResult = []): ContentModerationManager
    {
        $settings = $this->createMock(SettingsRegistry::class);
        $settings->method('get')->willReturnCallback(fn (string $k, mixed $d) => match ($k) {
            ContentModerationManager::SETTING_TYPES => $enabledTypes,
            ContentModerationManager::SETTING_WORKFLOW => 'editorial',
            default => $d,
        });

        $registry = $this->createMock(WorkflowRegistry::class);
        $registry->method('get')->willReturnCallback(fn (string $n): ?WorkflowDefinition => $n === 'editorial' ? $this->definition() : null);

        $workflowManager = $this->createMock(WorkflowManager::class);
        if ($applyResult !== []) {
            $workflowManager->method('apply')->willReturn($applyResult);
        }

        return new ContentModerationManager(
            $settings,
            $registry,
            $workflowManager,
            $this->createMock(RevisionManager::class),
        );
    }

    public function testIsEnabledFollowsTheSetting(): void
    {
        self::assertTrue($this->manager('page, post')->isEnabled('page'));
        self::assertFalse($this->manager('post')->isEnabled('page'));
        self::assertFalse($this->manager('')->isEnabled('page'));
    }

    public function testInitializeStampsTheInitialPlace(): void
    {
        $node = new Node('T', 't', 'page', 'en');
        $this->manager('page')->initialize($node);

        self::assertSame('draft', $node->getModerationState());
        self::assertSame(Node::STATUS_DRAFT, $node->getStatus());
    }

    public function testInitializeIsANoopWhenDisabled(): void
    {
        $node = new Node('T', 't', 'page', 'en');
        $this->manager('other')->initialize($node);

        self::assertNull($node->getModerationState());
    }

    public function testApplyToPublishPlaceSyncsStatus(): void
    {
        $node = new Node('T', 't', 'page', 'en');
        $node->setModerationState('review');

        $this->manager('page', ['workflow' => 'editorial', 'transition' => 'approve', 'from' => 'review', 'to' => 'published'])
            ->apply($node, 'approve');

        self::assertSame(Node::STATUS_PUBLISHED, $node->getStatus());
        self::assertNotNull($node->getPublishedAt());
    }

    public function testApplyAwayFromPublishPlaceUnpublishes(): void
    {
        $node = new Node('T', 't', 'page', 'en');
        $node->setModerationState('published')->publish();

        $this->manager('page', ['workflow' => 'editorial', 'transition' => 'unpublish', 'from' => 'published', 'to' => 'draft'])
            ->apply($node, 'unpublish');

        self::assertSame(Node::STATUS_DRAFT, $node->getStatus());
    }
}
