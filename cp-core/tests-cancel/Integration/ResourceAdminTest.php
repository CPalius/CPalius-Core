<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Controller\Admin\ResourceAdminController;
use App\Core\Resource\Admin\ResourceFieldResolver;
use App\Core\Resource\Admin\ResourceFormBuilder;
use App\Core\Resource\ResourceDefinition;
use App\Entity\User;
use App\Kernel;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

/**
 * The auto-admin field resolution and form building are the security-critical
 * parts: sensitive columns must never surface, the form must be a strict
 * allowlist (Manifesto Law 5.3). Uses the real User entity (email, password,
 * roles json, status, timestamps).
 */
#[CoversNothing]
final class ResourceAdminTest extends TestCase
{
    private ?Kernel $kernel = null;

    protected function tearDown(): void
    {
        $this->kernel?->shutdown();
        $this->kernel = null;
    }

    public function testSensitiveColumnsAreNeverExposed(): void
    {
        $resolver = $this->container()->get(ResourceFieldResolver::class);
        $properties = array_map(static fn ($d) => $d->property, $resolver->resolve(User::class));

        self::assertContains('email', $properties);
        self::assertContains('status', $properties);
        self::assertNotContains('password', $properties, 'password column must be refused');
        self::assertNotContains('roles', $properties, 'json role array must be refused');
        self::assertNotContains('data', $properties, 'json data bag must be refused');
    }

    public function testIdAndTimestampsAreReadonly(): void
    {
        $resolver = $this->container()->get(ResourceFieldResolver::class);
        $formProps = array_map(static fn ($d) => $d->property, $resolver->formFields(User::class));

        self::assertNotContains('id', $formProps);
        self::assertNotContains('createdAt', $formProps);
        self::assertContains('email', $formProps);
    }

    public function testFormIsAStrictAllowlist(): void
    {
        $c = $this->container();
        /** @var ResourceFormBuilder $builder */
        $builder = $c->get(ResourceFormBuilder::class);

        $definition = new ResourceDefinition(User::class, 'user', 'core', ['view', 'create', 'edit', 'delete'], false, false, null);
        $form = $builder->build(new User('x@y.com'), $definition);

        self::assertTrue($form->has('email'));
        self::assertFalse($form->has('password'), 'password must not be a form field');
        self::assertFalse($form->has('id'));
        self::assertFalse($form->has('roles'));
    }

    public function testControllerWiresUp(): void
    {
        self::assertInstanceOf(ResourceAdminController::class, $this->container()->get(ResourceAdminController::class));
    }

    private function container(): \Psr\Container\ContainerInterface
    {
        if ($this->kernel === null) {
            $this->kernel = new Kernel('test', true);
            $this->kernel->boot();
        }

        return $this->kernel->getContainer()->get('test.service_container');
    }
}
