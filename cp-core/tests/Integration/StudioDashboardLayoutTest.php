<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Controller\Admin\AACPController;
use App\Controller\Admin\AdminDashboardController;
use App\Core\Admin\StudioDashboardLayout;
use App\Tests\Support\IntegrationTestCase;
use PHPUnit\Framework\Attributes\CoversNothing;
use Symfony\Component\HttpFoundation\Request;

/** Studio desk: per-user card order, removed cards, row limits and the module cards. */
#[CoversNothing]
final class StudioDashboardLayoutTest extends IntegrationTestCase
{
    public function testArrangeOrdersRemovesAndClampsLimits(): void
    {
        $catalog = StudioDashboardLayout::catalog([
            'workbench' => ['available' => false],
            'modules' => [['key' => 'Forum', 'labelKey' => 'k', 'icon' => 'i', 'rows' => [['labelKey' => 'a', 'value' => 1]]]],
        ], false);

        $ids = array_column(StudioDashboardLayout::arrange($catalog, []), 'id');
        self::assertSame(['studio.recent', 'studio.totals', 'studio.mix', 'studio.shortcuts', 'module.forum'], $ids);

        $cards = StudioDashboardLayout::arrange($catalog, [
            'order' => ['module.forum', 'studio.mix', 'gone.card'],
            'removed' => ['studio.totals'],
            'limits' => ['studio.recent' => 999, 'module.forum' => 0],
        ]);
        self::assertSame(['module.forum', 'studio.mix', 'studio.recent', 'studio.totals', 'studio.shortcuts'], array_column($cards, 'id'));

        $byId = array_column($cards, null, 'id');
        self::assertTrue($byId['studio.totals']['removed']);
        self::assertSame(25, $byId['studio.recent']['limitValue']);
        self::assertSame(1, $byId['module.forum']['limitValue']);

        // Moving one card changes only the columns it left and entered; every other card keeps its place.
        $moved = StudioDashboardLayout::arrange($catalog, ['order' => ['module.forum'], 'columns' => ['module.forum' => 0]]);
        $in = static fn (int $col): array => array_values(array_column(array_filter($moved, static fn (array $c): bool => $c['column'] === $col), 'id'));
        self::assertSame(['module.forum', 'studio.recent'], $in(0));
        self::assertSame(['studio.totals', 'studio.mix', 'studio.shortcuts'], $in(1));

        self::assertSame(
            ['order' => ['a.b'], 'columns' => ['a.b' => 1], 'removed' => [], 'limits' => ['x' => 50]],
            StudioDashboardLayout::normalize(['order' => ['a.b', 'Bad Id', 5, 'a.b'], 'columns' => ['a.b' => 9, 'Bad Id' => 0], 'limits' => ['x' => '70', 'Bad Id' => 3]]),
        );
    }

    public function testDeskFollowsTheSavedLayout(): void
    {
        $this->container();
        $this->pushRequest();
        $user = $this->authenticateAs('admin');
        /** @var AdminDashboardController $controller */
        $controller = $this->container()->get(AdminDashboardController::class);
        $controller->setContainer($this->container());

        $token = $this->container()->get('security.csrf.token_manager')->getToken('studio_dashboard_layout')->getValue();
        $request = Request::create('/admin/dashboard/layout', 'POST', [
            '_token' => $token,
            'order' => ['studio.shortcuts', 'studio.recent'],
            'columns' => ['studio.shortcuts' => 0, 'studio.recent' => 0],
            'removed' => ['studio.mix'],
            'limits' => ['studio.recent' => 3],
        ]);
        $saved = json_decode((string) $controller->saveLayout($request)->getContent(), true);
        self::assertTrue($saved['success']);
        self::assertSame(['studio.mix'], $user->getDataValue(StudioDashboardLayout::DATA_KEY)['removed']);

        $html = (string) $controller->index()->getContent();
        self::assertLessThan(strpos($html, 'data-studio-widget="studio.recent"'), strpos($html, 'data-studio-widget="studio.shortcuts"'));
        self::assertStringNotContainsString('data-studio-widget="studio.mix"', $html);
        // The removed card is still listed in the editor so it can be switched back on.
        self::assertStringContainsString('data-card-id="studio.mix"', $html);
        self::assertStringContainsString('data-cp-layout-modal', $html);

        $bad = Request::create('/admin/dashboard/layout', 'POST', ['_token' => 'nope']);
        self::assertSame(400, $controller->saveLayout($bad)->getStatusCode());
    }
    public function testAacpDeskFollowsItsOwnSavedLayout(): void
    {
        $this->container();
        $this->pushRequest();
        $user = $this->authenticateAs('admin');
        /** @var AACPController $controller */
        $controller = $this->container()->get(AACPController::class);

        $token = $this->container()->get('security.csrf.token_manager')->getToken('aacp_dashboard_layout')->getValue();
        $saved = json_decode((string) $controller->saveDashboardLayout(Request::create('/aacp/dashboard/layout', 'POST', [
            '_token' => $token,
            'order' => ['system.subsystems', 'platform.work'],
            'columns' => ['system.subsystems' => 0, 'platform.work' => 0],
            'removed' => ['platform.volume'],
        ]))->getContent(), true);
        self::assertTrue($saved['success']);
        self::assertSame(['platform.volume'], $user->getDataValue('aacp_dashboard_layout')['removed']);

        $html = (string) $controller->dashboard()->getContent();
        self::assertStringNotContainsString('data-aacp-widget="platform.volume"', $html);
        self::assertLessThan(strpos($html, 'data-aacp-widget="platform.work"'), strpos($html, 'data-aacp-widget="system.subsystems"'));
        self::assertStringContainsString('data-card-id="platform.volume"', $html, 'the removed card stays in the editor');
        self::assertStringContainsString('data-cp-layout-modal', $html);
    }
}
