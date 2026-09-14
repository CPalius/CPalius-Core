<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Core\Admin\StudioWorkbenchService;
use App\Core\Content\ContentModerationManager;
use App\Core\Settings\SettingDefinition;
use App\Core\Settings\SettingsRegistry;
use App\Entity\Node;
use App\Entity\User;
use App\Tests\Support\IntegrationTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * The Studio desk answering "what is waiting on a human" instead of "how much
 * has been published".
 *
 * The property under test is that the answer comes from the workflow engine,
 * not from Node::status. That is what makes the panel portable: an install
 * whose places are quote/approved/invoiced gets the same screen as an
 * editorial one, and core never learns which of the two it is running.
 */
#[CoversClass(StudioWorkbenchService::class)]
final class StudioWorkbenchTest extends IntegrationTestCase
{
    public function testRecordsParkedInANonFinalPlaceAreCounted(): void
    {
        $this->bootWithModeration('admin');

        $this->seed('draft', 2);
        $this->seed('review', 3);
        // Already live: finished work, not a queue. Listing it would bury the
        // items that still need someone.
        $this->seed('published', 4);

        $states = $this->indexByState($this->workbench()->build()['states']);

        self::assertSame(2, $states['draft']['count'] ?? null);
        self::assertSame(3, $states['review']['count'] ?? null);
        self::assertArrayNotHasKey('published', $states, 'a publish place is not a queue');
    }

    public function testPlaceLabelsComeFromTheWorkflowDefinition(): void
    {
        $this->bootWithModeration('admin');
        $this->seed('review', 1);

        $states = $this->indexByState($this->workbench()->build()['states']);

        // 'In review' is written in cp-content/config/sync/workflow/editorial.yaml.
        // Nothing in the service names it, which is the point.
        self::assertSame('In review', $states['review']['label'] ?? null);
    }

    public function testAQueueIsActionableOnlyWhenTheViewerHoldsAnExitCapability(): void
    {
        $this->bootWithModeration('admin');

        // Authored by the editor, so the same two records are visible to both
        // viewers below. Without an author, node.post.view.own would hide them
        // from the second viewer and the comparison would prove nothing about
        // the capability.
        $editor = $this->makeUser('editor', 'workbench-editor@example.test');
        $this->seed('review', 2, $editor);

        $asAdmin = $this->indexByState($this->workbench()->build()['states']);
        self::assertTrue($asAdmin['review']['actionable'] ?? false, 'the wildcard role can move a review item on');

        // The editor role holds node.post.* but neither content.moderate nor
        // content.publish, and every exit from 'review' is gated by one of
        // those. The records stay visible and stay parked; the panel must not
        // promise an action the transition guard would refuse.
        $this->authenticate($editor);
        $pulse = $this->workbench()->build();

        $asEditor = $this->indexByState($pulse['states']);
        self::assertSame(2, $asEditor['review']['count'] ?? null, 'the queue is visible to its author');
        self::assertFalse($asEditor['review']['actionable'], 'no exit, no claim of one');
        self::assertSame(0, $pulse['actionable'], 'the headline only counts work this viewer can do');
    }

    public function testYourOwnUnfinishedWorkIsCountedSeparately(): void
    {
        // No moderation here on purpose: this counter reads publication
        // status, and an enabled workflow would stamp its initial place over
        // the fixture, making the test assert the listener instead.
        $this->container();
        $this->pushRequest();
        $user = $this->authenticateAs('admin');

        $mine = new Node('My draft', 'my-draft', 'post', 'tr');
        $mine->setAuthor($user);
        $this->em()->persist($mine);

        $minePublished = new Node('My live post', 'my-live-post', 'post', 'tr');
        $minePublished->setAuthor($user)->setStatus(Node::STATUS_PUBLISHED);
        $this->em()->persist($minePublished);

        // Somebody else's draft: their problem, not a line on this user's desk.
        $this->em()->persist(new Node('Foreign draft', 'foreign-draft', 'post', 'tr'));

        $this->em()->flush();

        $mineSummary = $this->workbench()->build()['mine'];

        self::assertSame(1, $mineSummary['unfinished']);
        self::assertSame(2, $mineSummary['authored']);
    }

    public function testAnInstallationWithoutModerationGetsNoQueue(): void
    {
        $this->container();
        $this->pushRequest();
        $this->authenticateAs('admin');

        // Moderation off: the workbench must stay quiet rather than inventing
        // a queue out of publication status.
        $this->seed('review', 3);

        $pulse = $this->workbench()->build();

        self::assertFalse($pulse['moderated']);
        self::assertSame([], $pulse['states']);
    }

    public function testStudioPanelsCanBeFoldedAwayAndStayFolded(): void
    {
        $this->container();
        $this->pushRequest();
        $user = $this->authenticateAs('admin');

        $user->setDataValue('studio_dashboard_widgets', ['hidden' => ['studio.mix']]);
        $this->em()->flush();

        /** @var \App\Controller\Admin\AdminDashboardController $controller */
        $controller = $this->container()->get(\App\Controller\Admin\AdminDashboardController::class);
        $html = (string) $controller->index()->getContent();

        foreach (['studio.recent', 'studio.mix', 'studio.shortcuts'] as $widgetId) {
            self::assertStringContainsString('data-studio-widget="'.$widgetId.'"', $html);
        }

        // Server-rendered, so a folded panel does not flash open before a
        // script reaches it.
        self::assertStringContainsString(
            'class="studio-cmd-panel is-collapsed" data-studio-widget="studio.mix"',
            $html,
        );
        self::assertStringNotContainsString(
            'class="studio-cmd-panel is-collapsed" data-studio-widget="studio.recent"',
            $html,
            'only the chosen panel folds',
        );
    }

    private function bootWithModeration(string $role): User
    {
        $this->container();
        $this->pushRequest();
        $user = $this->authenticateAs($role);

        // Settings have no runtime setter: values come from definitions plus
        // DB overrides, so the fixture registers a definition whose default is
        // what it needs (the pattern ContentModerationTest established).
        /** @var SettingsRegistry $settings */
        $settings = $this->container()->get(SettingsRegistry::class);
        $settings->addDefinition(new SettingDefinition(
            key: ContentModerationManager::SETTING_TYPES,
            label: ContentModerationManager::SETTING_TYPES,
            type: 'text',
            default: 'post',
            variants: [],
            module: 'core',
            group: 'content',
        ));

        return $user;
    }

    private function seed(string $state, int $count, ?User $author = null): void
    {
        $em = $this->em();

        for ($i = 0; $i < $count; ++$i) {
            $node = new Node(ucfirst($state).' '.$i, $state.'-'.$i, 'post', 'tr');
            // Stamped explicitly: ContentModerationManager::initialize() only
            // fills a null place, so the fixture keeps control of the state.
            $node->setModerationState($state);
            if ($state === 'published') {
                $node->setStatus(Node::STATUS_PUBLISHED);
            }
            if ($author instanceof User) {
                $node->setAuthor($author);
            }
            $em->persist($node);
        }

        $em->flush();
    }

    /**
     * A persisted user that is NOT installed in the token storage, so one test
     * can hand the same records to two different viewers.
     */
    private function makeUser(string $cpaliusRole, string $email): User
    {
        $user = new User($email);
        $user->setPassword('x')->setCpaliusRoles([$cpaliusRole])->setStatus(User::STATUS_ACTIVE);

        $this->em()->persist($user);
        $this->em()->flush();

        return $user;
    }

    /**
     * @param list<array{state: string, label: string, count: int, actionable: bool}> $states
     *
     * @return array<string, array{state: string, label: string, count: int, actionable: bool}>
     */
    private function indexByState(array $states): array
    {
        $out = [];
        foreach ($states as $state) {
            $out[$state['state']] = $state;
        }

        return $out;
    }

    private function workbench(): StudioWorkbenchService
    {
        /** @var StudioWorkbenchService $service */
        $service = $this->container()->get(StudioWorkbenchService::class);

        return $service;
    }
}
