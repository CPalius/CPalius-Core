<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\HttpKernel\HttpKernelBrowser;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

/**
 * Base for tests that need a real, booted kernel and a real database.
 *
 * Every integration test used to carry its own copy of the kernel boot and the
 * SchemaTool drop/create dance — fifteen copies, four spellings, and one shared
 * defect (see IntegrationSchema for why dropSchema() could not be relied on).
 * Keeping that setup in one place is not only tidier: it means a fix like the
 * one in IntegrationSchema reaches every test at once instead of fourteen
 * times by hand.
 *
 * The kernel is booted lazily on first use and shut down in tearDown(), so each
 * test method gets a clean container and a clean schema.
 */
abstract class IntegrationTestCase extends TestCase
{
    private ?\App\Kernel $kernel = null;

    private ?ContainerInterface $container = null;

    protected function tearDown(): void
    {
        $this->kernel?->shutdown();
        $this->kernel = null;
        $this->container = null;

        parent::tearDown();
    }

    /**
     * The test container, with the schema reset to the current mapping.
     */
    protected function container(): ContainerInterface
    {
        if ($this->container !== null) {
            return $this->container;
        }

        $this->kernel = new \App\Kernel('test', true);
        $this->kernel->boot();

        /** @var ContainerInterface $container */
        $container = $this->kernel->getContainer()->get('test.service_container');
        $this->container = $container;

        /** @var EntityManagerInterface $em */
        $em = $container->get(EntityManagerInterface::class);
        IntegrationSchema::reset($em);

        return $container;
    }

    protected function em(): EntityManagerInterface
    {
        /** @var EntityManagerInterface $em */
        $em = $this->container()->get(EntityManagerInterface::class);

        return $em;
    }

    /**
     * Pushes a request with a session onto the stack.
     *
     * Controllers invoked directly (rather than through the HTTP kernel) still
     * reach for the current request — for flash messages and CSRF tokens — and
     * get a null without this.
     */
    protected function pushRequest(): Request
    {
        $request = new Request();
        $request->setSession(new Session(new MockArraySessionStorage()));

        /** @var RequestStack $stack */
        $stack = $this->container()->get(RequestStack::class);
        $stack->push($request);

        return $request;
    }

    /**
     * Persists a user holding $cpaliusRole and installs it in the token
     * storage, so capability guards see a real subject.
     *
     * Roles come from cp-content/config/sync/user.role.*.yaml: "admin" holds
     * the '*' wildcard, "member" holds only forum and account capabilities.
     */
    protected function authenticateAs(string $cpaliusRole, ?string $email = null): User
    {
        $user = new User($email ?? $cpaliusRole.'@example.test');
        $user->setPassword('x')->setCpaliusRoles([$cpaliusRole]);

        $em = $this->em();
        $em->persist($user);
        $em->flush();

        $this->authenticate($user);

        return $user;
    }

    protected function authenticate(User $user): void
    {
        /** @var TokenStorageInterface $tokenStorage */
        $tokenStorage = $this->container()->get(TokenStorageInterface::class);
        $tokenStorage->setToken(new UsernamePasswordToken($user, 'main', $user->getRoles()));
    }

    /**
     * A browser over the already-booted kernel, for tests that exercise real
     * routing rather than calling a controller directly.
     */
    protected function browser(): HttpKernelBrowser
    {
        $this->container();

        \assert($this->kernel !== null);

        return new HttpKernelBrowser($this->kernel);
    }
}
