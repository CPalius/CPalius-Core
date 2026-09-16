<?php

declare(strict_types=1);

namespace Modules\Showcase\Service;

use Doctrine\ORM\EntityManagerInterface;
use Modules\Showcase\Entity\ShowcaseItem;
use Modules\Showcase\Entity\ShowcaseLink;
use Symfony\Component\Routing\Exception\ExceptionInterface as RoutingException;
use Symfony\Component\Routing\Exception\MethodNotAllowedException;
use Symfony\Component\Routing\Exception\ResourceNotFoundException;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Routing\RouterInterface;

/**
 * Connects a showcase entry to the rest of the site — the support thread in the
 * forum, the release post on the blog, a documentation page — without the
 * Showcase module knowing that Forum or Blog exist.
 *
 * The owner pastes a URL. This service asks the ROUTER what that URL is: if it
 * resolves to a route in this installation the link is stored as route name +
 * parameters, otherwise it is stored as a plain external address. Rendering
 * regenerates the internal ones, so:
 *
 *   - deactivating Forum makes its routes unknown and the link simply stops
 *     being rendered, instead of 404ing a visitor;
 *   - a module that changes its URL scheme carries its inbound links with it;
 *   - no foreign key and no `use Modules\Forum\...` ever appears here.
 *
 * Internal links are restricted to public areas: a member must not be able to
 * park a link to /admin or /aacp on a page everyone can see.
 */
final class ShowcaseLinkService
{
    private const MAX_LINKS_PER_ITEM = 12;

    /** Path prefixes a member-supplied internal link may never point at. */
    private const PRIVATE_PREFIXES = ['/admin', '/aacp', '/api', '/hesap', '/_'];

    /**
     * Route-name prefix => icon + label key. Presentation only: these are plain
     * strings, so a missing module costs nothing but a generic icon.
     */
    private const ROUTE_HINTS = [
        'forum_' => ['icon' => 'bi-chat-dots', 'label' => 'showcase.links.kind.forum'],
        'blog_' => ['icon' => 'bi-journal-text', 'label' => 'showcase.links.kind.blog'],
        'showcase_' => ['icon' => 'bi-grid', 'label' => 'showcase.links.kind.showcase'],
        'page_' => ['icon' => 'bi-file-earmark-text', 'label' => 'showcase.links.kind.page'],
    ];

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly RouterInterface $router,
        private readonly ShowcaseUrlValidator $urlValidator,
    ) {
    }

    /**
     * @return array{link: ?ShowcaseLink, error: ?string}
     */
    public function attach(ShowcaseItem $item, string $rawUrl, ?string $label): array
    {
        if ($item->getLinks()->count() >= self::MAX_LINKS_PER_ITEM) {
            return ['link' => null, 'error' => 'showcase.links.error.too_many'];
        }

        $rawUrl = trim($rawUrl);

        if ($rawUrl === '') {
            return ['link' => null, 'error' => 'showcase.links.error.empty'];
        }

        $internal = $this->matchInternal($rawUrl);

        if ($internal !== null) {
            $link = new ShowcaseLink($item, ShowcaseLink::KIND_INTERNAL);
            $link->setRoute($internal['route'], $internal['params']);
        } else {
            $url = $this->urlValidator->sanitize($rawUrl);

            if ($url === null) {
                return ['link' => null, 'error' => 'showcase.links.error.invalid_url'];
            }

            $link = new ShowcaseLink($item, ShowcaseLink::KIND_EXTERNAL);
            $link->setUrl($url);
        }

        $link->setLabel($label);
        $link->setWeight($item->getLinks()->count() * 10);

        $item->addLink($link);
        $this->entityManager->persist($link);
        $this->entityManager->flush();

        return ['link' => $link, 'error' => null];
    }

    public function detach(ShowcaseItem $item, ShowcaseLink $link): void
    {
        // Guards against a link id from another item being passed in a crafted
        // form post; the controller looks the link up on the item, this is belt
        // and braces for any other caller.
        if ($link->getItem()->getId() !== $item->getId()) {
            return;
        }

        $item->removeLink($link);
        $this->entityManager->remove($link);
        $this->entityManager->flush();
    }

    /**
     * Renderable links for one item. Internal links whose route has disappeared
     * are dropped silently — that is the whole point of storing route names.
     *
     * @return list<array{id: int, url: string, label: ?string, internal: bool, icon: string, kindLabel: ?string, host: ?string}>
     */
    public function resolveAll(ShowcaseItem $item, ?string $locale = null): array
    {
        $out = [];

        foreach ($item->getLinks() as $link) {
            $resolved = $this->resolve($link, $locale);

            if ($resolved !== null) {
                $out[] = $resolved;
            }
        }

        return $out;
    }

    /**
     * @return array{id: int, url: string, label: ?string, internal: bool, icon: string, kindLabel: ?string, host: ?string}|null
     */
    public function resolve(ShowcaseLink $link, ?string $locale = null): ?array
    {
        if (!$link->isInternal()) {
            $url = $this->urlValidator->sanitize($link->getUrl());

            if ($url === null) {
                return null;
            }

            return [
                'id' => (int) $link->getId(),
                'url' => $url,
                'label' => $link->getLabel(),
                'internal' => false,
                'icon' => 'bi-box-arrow-up-right',
                'kindLabel' => null,
                'host' => $this->urlValidator->displayHost($url),
            ];
        }

        $routeName = $link->getRouteName();

        if ($routeName === null) {
            return null;
        }

        $params = $link->getRouteParams();

        if ($locale !== null && \array_key_exists('_locale', $params)) {
            $params['_locale'] = $locale;
        }

        try {
            $url = $this->router->generate($routeName, $params, UrlGeneratorInterface::ABSOLUTE_PATH);
        } catch (RoutingException) {
            // The owning module was deactivated or renamed the route.
            return null;
        }

        $hint = $this->hintFor($routeName);

        return [
            'id' => (int) $link->getId(),
            'url' => $url,
            'label' => $link->getLabel(),
            'internal' => true,
            'icon' => $hint['icon'],
            'kindLabel' => $hint['label'],
            'host' => null,
        ];
    }

    /**
     * Resolves a pasted address to a route in this installation.
     *
     * @return array{route: string, params: array<string, string|int>}|null
     */
    private function matchInternal(string $rawUrl): ?array
    {
        $path = $this->toLocalPath($rawUrl);

        if ($path === null) {
            return null;
        }

        foreach (self::PRIVATE_PREFIXES as $prefix) {
            if (str_starts_with($path, $prefix.'/') || $path === $prefix) {
                return null;
            }
        }

        try {
            $matched = $this->router->match($path);
        } catch (ResourceNotFoundException|MethodNotAllowedException) {
            // Unknown path, or an endpoint that only answers POST — neither is a
            // link target. Treat it as external so the URL validator decides.
            return null;
        }

        $route = $matched['_route'] ?? null;

        if (!\is_string($route) || $route === '') {
            return null;
        }

        $params = [];
        foreach ($matched as $key => $value) {
            if (str_starts_with((string) $key, '_') && $key !== '_locale') {
                continue;
            }

            if (\is_string($value) || \is_int($value)) {
                $params[(string) $key] = $value;
            }
        }

        return ['route' => $route, 'params' => $params];
    }

    /**
     * Path portion of a URL that points at THIS site, or null when it points
     * elsewhere. The host comparison is what keeps an attacker from getting an
     * off-site address stored as an "internal" link.
     */
    private function toLocalPath(string $rawUrl): ?string
    {
        if (str_starts_with($rawUrl, '/') && !str_starts_with($rawUrl, '//')) {
            return $this->pathOnly($rawUrl);
        }

        $clean = $this->urlValidator->sanitize($rawUrl);

        if ($clean === null) {
            return null;
        }

        $host = parse_url($clean, \PHP_URL_HOST);
        $siteHost = $this->router->getContext()->getHost();

        if (!\is_string($host) || $host === '' || strcasecmp($host, $siteHost) !== 0) {
            return null;
        }

        $path = parse_url($clean, \PHP_URL_PATH);

        return \is_string($path) && $path !== '' ? $this->pathOnly($path) : null;
    }

    private function pathOnly(string $path): string
    {
        $path = explode('#', explode('?', $path, 2)[0], 2)[0];
        $baseUrl = $this->router->getContext()->getBaseUrl();

        if ($baseUrl !== '' && str_starts_with($path, $baseUrl)) {
            $path = substr($path, \strlen($baseUrl));
        }

        return $path === '' ? '/' : $path;
    }

    /**
     * @return array{icon: string, label: ?string}
     */
    private function hintFor(string $routeName): array
    {
        foreach (self::ROUTE_HINTS as $prefix => $hint) {
            if (str_starts_with($routeName, $prefix)) {
                return $hint;
            }
        }

        return ['icon' => 'bi-link-45deg', 'label' => null];
    }
}
