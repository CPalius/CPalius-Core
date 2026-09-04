<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Kernel;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpKernel\HttpKernelBrowser;

/**
 * Law 6.4: anonymous front-office traffic must not start a PHP session.
 */
#[CoversNothing]
final class AnonymousSessionTest extends TestCase
{
    private ?Kernel $kernel = null;

    protected function tearDown(): void
    {
        $this->kernel?->shutdown();
        $this->kernel = null;
    }

    public function testHomepageRedirectDoesNotSetASessionCookie(): void
    {
        $browser = $this->browser();
        $browser->followRedirects(false);
        $browser->request('GET', '/');

        $this->assertNoSessionCookie($browser);
        self::assertLessThan(500, $browser->getResponse()->getStatusCode());
    }

    public function testLocaleHomeDoesNotSetASessionCookie(): void
    {
        $browser = $this->browser();
        $browser->followRedirects(false);
        $browser->request('GET', '/tr');

        $this->assertNoSessionCookie($browser);
        self::assertLessThan(500, $browser->getResponse()->getStatusCode());
    }

    private function browser(): HttpKernelBrowser
    {
        $this->kernel = new Kernel('test', true);
        $this->kernel->boot();

        $container = $this->kernel->getContainer()->get('test.service_container');
        /** @var EntityManagerInterface $entityManager */
        $entityManager = $container->get(EntityManagerInterface::class);
        $metadata = $entityManager->getMetadataFactory()->getAllMetadata();

        if ($metadata !== []) {
            $schemaTool = new SchemaTool($entityManager);
            $schemaTool->dropSchema($metadata);
            $schemaTool->createSchema($metadata);
        }

        return new HttpKernelBrowser($this->kernel);
    }

    private function assertNoSessionCookie(HttpKernelBrowser $browser): void
    {
        foreach ($browser->getResponse()->headers->getCookies() as $cookie) {
            self::assertFalse(
                $this->looksLikeSessionCookie($cookie),
                sprintf('Anonymous response set session cookie "%s" (Law 6.4).', $cookie->getName()),
            );
        }
    }

    private function looksLikeSessionCookie(Cookie $cookie): bool
    {
        $name = strtolower($cookie->getName());

        return str_contains($name, 'sess') || $name === 'phpssid' || str_ends_with($name, 'id') && str_contains($name, 'php');
    }
}
