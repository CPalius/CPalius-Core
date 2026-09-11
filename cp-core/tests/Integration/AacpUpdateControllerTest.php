<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Controller\Admin\AACPUpdateController;
use App\Tests\Support\IntegrationTestCase;
use Doctrine\Migrations\Metadata\Storage\MetadataStorage;
use Doctrine\Migrations\Version\Direction;
use Doctrine\Migrations\Version\ExecutionResult;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The AACP updates screen (T3.6).
 *
 * These assertions render the page rather than checking that a request is
 * redirected somewhere: a 302 to the login form proves the firewall works and
 * nothing about the screen. The properties being held are that opening it
 * changes nothing, that applying requires a real POST with a token, and that
 * the capability actually guards it.
 */
#[CoversClass(AACPUpdateController::class)]
final class AacpUpdateControllerTest extends IntegrationTestCase
{
    public function testIndexRendersADryRunAndChangesNothing(): void
    {
        $controller = $this->boot('admin');

        $before = $this->settingsRowCount();

        $response = $controller->index();

        self::assertSame(200, $response->getStatusCode());

        $html = (string) $response->getContent();

        // Labels come from the translator rather than being hard-coded: the
        // admin UI renders in the configured locale (Turkish by default here),
        // so asserting English strings would test the fixture's language.
        // Every step of the pipeline must be accounted for on the page, so an
        // operator cannot mistake a missing row for "nothing to do".
        foreach (['migrations', 'update-hooks', 'modules', 'config', 'cache'] as $step) {
            self::assertStringContainsString(
                $this->trans('aacp.updates.step.'.$step),
                $html,
                sprintf('the "%s" step is listed', $step),
            );
        }

        self::assertStringContainsString('cp:update --dry-run', $html, 'the shell equivalent is offered');

        // The decisive assertion: a GET is an inspection. If the ledger or any
        // other row had been written, this count would move.
        self::assertSame($before, $this->settingsRowCount(), 'opening the page must not write anything');
    }

    public function testApplyRequiresAValidCsrfToken(): void
    {
        $controller = $this->boot('admin');

        $request = new Request(request: ['_token' => 'forged']);
        $request->setMethod('POST');

        // Without this, an update would be reachable by getting an
        // administrator to visit a crafted page.
        $this->expectException(BadRequestHttpException::class);
        $controller->apply($request);
    }

    public function testApplyRunsThePipelineAndReportsEachStep(): void
    {
        $controller = $this->boot('admin');

        /** @var CsrfTokenManagerInterface $csrf */
        $csrf = $this->container()->get('security.csrf.token_manager');

        $request = new Request(request: ['_token' => $csrf->getToken('aacp_updates_apply')->getValue()]);
        $request->setMethod('POST');

        $response = $controller->apply($request);

        self::assertSame(200, $response->getStatusCode());

        $html = (string) $response->getContent();
        self::assertStringContainsString(
            $this->trans('aacp.updates.applied.heading'),
            $html,
            'the run is reported rather than reduced to a flash',
        );
        self::assertStringContainsString($this->trans('aacp.updates.applied.ok'), $html);
    }

    public function testTheScreenIsGuardedByItsCapability(): void
    {
        // "member" holds forum and account capabilities only. The guard is an
        // #[IsGranted] attribute, which the kernel enforces on the request —
        // so this asserts the voter refuses, which is what the attribute asks.
        $this->boot('member');

        /** @var \Symfony\Bundle\SecurityBundle\Security $security */
        $security = $this->container()->get(\Symfony\Bundle\SecurityBundle\Security::class);

        self::assertFalse($security->isGranted('system.update.manage'));

        $this->authenticateAs('admin', 'second-admin@example.test');
        self::assertTrue($security->isGranted('system.update.manage'), 'admin holds it through the wildcard');
    }

    public function testAnonymousAccessIsRefused(): void
    {
        $this->container();
        $this->pushRequest();

        /** @var \Symfony\Bundle\SecurityBundle\Security $security */
        $security = $this->container()->get(\Symfony\Bundle\SecurityBundle\Security::class);

        self::assertFalse($security->isGranted('system.update.manage'), 'no subject, no update');
    }

    private function boot(string $role): AACPUpdateController
    {
        $container = $this->container();
        $this->pushRequest();
        $this->authenticateAs($role);
        $this->stampMigrationsAsExecuted();

        /** @var AACPUpdateController $controller */
        $controller = $container->get(AACPUpdateController::class);

        return $controller;
    }

    /**
     * The test schema comes from SchemaTool, so the migration table is empty
     * even though every table exists. Stamping makes the fixture represent a
     * migrated installation; without it the runner would correctly try to
     * apply sixty migrations against tables that are already there.
     */
    private function stampMigrationsAsExecuted(): void
    {
        $factory = $this->container()->get('doctrine.migrations.dependency_factory');

        /** @var MetadataStorage $storage */
        $storage = $factory->getMetadataStorage();
        $storage->ensureInitialized();

        $executed = $storage->getExecutedMigrations();

        foreach ($factory->getMigrationRepository()->getMigrations()->getItems() as $migration) {
            if (!$executed->hasMigration($migration->getVersion())) {
                $storage->complete(new ExecutionResult($migration->getVersion(), Direction::UP));
            }
        }
    }

    private function trans(string $key): string
    {
        /** @var TranslatorInterface $translator */
        $translator = $this->container()->get(TranslatorInterface::class);

        return $translator->trans($key);
    }

    private function settingsRowCount(): int
    {
        return (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM cp_settings');
    }
}
