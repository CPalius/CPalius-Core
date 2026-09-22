<?php

declare(strict_types=1);

namespace Modules\Seo\EventSubscriber;

use Doctrine\ORM\EntityManagerInterface;
use Modules\Seo\Entity\SeoRedirect;
use Modules\Seo\Redirect\SeoRedirectPath;
use Modules\Seo\Repository\SeoRedirectRepository;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Turns a 404 into a 301/302 when Studio has a row for that path.
 *
 * Runs on EXCEPTION, after static routes have already lost, and before the
 * themed 404 page is rendered. A missing table (SQL not applied yet) or a
 * lookup error is swallowed: the visitor still sees 404 rather than a 500
 * about a feature they never opened.
 */
final class SeoRedirectSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly SeoRedirectRepository $redirects,
        private readonly EntityManagerInterface $entityManager,
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::EXCEPTION => [['onKernelException', 10]],
        ];
    }

    public function onKernelException(ExceptionEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        if (!$event->getThrowable() instanceof NotFoundHttpException) {
            return;
        }

        $request = $event->getRequest();
        $candidates = self::sourceCandidates($request);
        if ($candidates === []) {
            return;
        }

        try {
            $redirect = $this->redirects->findOneActiveBySources($candidates);
        } catch (\Throwable $e) {
            $this->logger?->notice('SEO redirect lookup failed.', ['exception' => $e]);

            return;
        }

        if (!$redirect instanceof SeoRedirect) {
            return;
        }

        $target = SeoRedirectPath::normalizeTarget($redirect->getTargetUrl());
        if ($target === null || ltrim($target, '/') === $redirect->getSourcePath()) {
            return;
        }

        try {
            $redirect->recordHit();
            $this->entityManager->flush();
        } catch (\Throwable $e) {
            $this->logger?->notice('SEO redirect hit could not be recorded.', ['exception' => $e]);
        }

        $event->setResponse(new RedirectResponse($target, $redirect->getStatusCode()));
    }

    /**
     * @return list<string>
     */
    private static function sourceCandidates(Request $request): array
    {
        $paths = [ltrim($request->getPathInfo(), '/')];
        $uriPath = ltrim((string) parse_url($request->getRequestUri(), PHP_URL_PATH), '/');
        if ($uriPath !== '') {
            $paths[] = $uriPath;
        }

        $candidates = [];
        foreach ($paths as $path) {
            if ($path === '' || self::isProtectedPath($path)) {
                continue;
            }

            $candidates[] = $path;
            if (preg_match('#^[a-z]{2}(?:-[a-z]{2})?+/(.+)$#i', $path, $matches) === 1) {
                $candidates[] = $matches[1];
            }
        }

        return array_values(array_unique($candidates));
    }

    private static function isProtectedPath(string $path): bool
    {
        foreach (['admin', 'aacp', '_profiler', '_wdt', '_fragment', '_error'] as $prefix) {
            if ($path === $prefix || str_starts_with($path, $prefix.'/')) {
                return true;
            }
        }

        return false;
    }
}
