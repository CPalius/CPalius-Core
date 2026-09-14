<?php

declare(strict_types=1);

namespace App\Tests\Unit\Core\EventListener;

use App\Core\EventListener\LocaleListener;
use App\Core\Localization\LocaleProvider;
use App\Repository\LocaleRepository;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

#[CoversClass(LocaleListener::class)]
final class LocaleListenerTest extends TestCase
{
    private LocaleListener $listener;

    private HttpKernelInterface $kernel;

    protected function setUp(): void
    {
        $repository = $this->createMock(LocaleRepository::class);
        $repository->method('findActive')->willReturn([]);

        $cache = $this->createMock(CacheInterface::class);
        $cache->method('get')->willReturnCallback(function (string $key, callable $callback): mixed {
            return $callback($this->createMock(ItemInterface::class));
        });

        $this->listener = new LocaleListener(new LocaleProvider($repository, $cache, 'tr,en', 'tr'));
        $this->kernel = $this->createMock(HttpKernelInterface::class);
    }

    public function testLocaleUrlPrefixDoesNotQueueACookie(): void
    {
        $request = Request::create('https://example.test/tr/blog/hello');
        $this->listener->onKernelRequest(new RequestEvent($this->kernel, $request, HttpKernelInterface::MAIN_REQUEST));

        self::assertSame('tr', $request->getLocale());
        self::assertFalse($request->attributes->has('_cp_locale_write'));

        $response = new Response('<html></html>', 200, ['Content-Type' => 'text/html']);
        $this->listener->onKernelResponse(new ResponseEvent(
            $this->kernel,
            $request,
            HttpKernelInterface::MAIN_REQUEST,
            $response,
        ));

        self::assertFalse($response->headers->has('Set-Cookie'));
    }

    public function testQueryLocaleStillWritesCookie(): void
    {
        $request = Request::create('https://example.test/', 'GET', ['_locale' => 'en']);
        $this->listener->onKernelRequest(new RequestEvent($this->kernel, $request, HttpKernelInterface::MAIN_REQUEST));

        self::assertSame('en', $request->getLocale());
        self::assertSame('en', $request->attributes->get('_cp_locale_write'));
    }
}
