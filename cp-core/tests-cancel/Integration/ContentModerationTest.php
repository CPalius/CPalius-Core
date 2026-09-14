<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Core\Content\ContentModerationManager;
use App\Core\Settings\SettingDefinition;
use App\Core\Settings\SettingsRegistry;
use App\Entity\Node;
use App\Tests\Support\IntegrationTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Content moderation: a workflow place kept separate from publication status.
 *
 * Conflating the two is the usual CMS mistake — "published" then means both
 * "editorially approved" and "visible", and a site cannot express a piece that
 * is approved but scheduled, or visible but under review. Here moderation_state
 * is its own column, and the manager is what keeps it and status consistent.
 */
#[CoversClass(ContentModerationManager::class)]
final class ContentModerationTest extends IntegrationTestCase
{
    public function testNewModeratedNodeIsInitialised(): void
    {
        $container = $this->container();
        $em = $this->em();

        $this->enableModerationFor('page');

        /** @var ContentModerationManager $moderation */
        $moderation = $container->get(ContentModerationManager::class);

        self::assertTrue($moderation->isEnabled('page'));
        self::assertFalse($moderation->isEnabled('post'), 'moderation is opt-in per node type');

        $node = new Node('Draft article', 'draft-article', 'page', 'en');
        self::assertNull($node->getModerationState(), 'nothing is stamped before initialise()');

        $moderation->initialize($node);
        $em->persist($node);
        $em->flush();

        $state = $moderation->currentState($node);
        self::assertNotNull($state, 'a moderated node lands on the workflow initial place');
        self::assertSame($state, $node->getModerationState());

        // Idempotent: calling it again must not reset a node that has already
        // moved on, which is what makes it safe to call from a listener.
        $moderation->initialize($node);
        self::assertSame($state, $node->getModerationState());

        // Transitions are capability-guarded, so an anonymous caller is
        // offered none — the workflow is not a free choice of any state.
        self::assertSame([], $moderation->availableTransitions($node), 'no subject, no transitions');

        $this->authenticateAs('admin');
        self::assertNotSame(
            [],
            $moderation->availableTransitions($node),
            'an authorised editor is offered the transitions leaving the initial place',
        );

        // A node of an unmoderated type is left entirely alone — no state, no
        // implicit workflow.
        $unmoderated = new Node('Post', 'a-post', 'post', 'en');
        $moderation->initialize($unmoderated);
        self::assertNull($unmoderated->getModerationState());
    }

    /**
     * Settings have no runtime setter: values come from definitions plus DB
     * overrides, so a test registers a definition whose default is what it
     * needs.
     */
    private function enableModerationFor(string $nodeType): void
    {
        /** @var SettingsRegistry $settings */
        $settings = $this->container()->get(SettingsRegistry::class);

        $settings->addDefinition(new SettingDefinition(
            key: ContentModerationManager::SETTING_TYPES,
            label: ContentModerationManager::SETTING_TYPES,
            type: 'text',
            default: $nodeType,
            variants: [],
            module: 'core',
            group: 'content',
        ));
    }
}
