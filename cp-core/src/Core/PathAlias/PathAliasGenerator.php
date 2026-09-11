<?php

declare(strict_types=1);

namespace App\Core\PathAlias;

use App\Core\PathAlias\Repository\PathAliasPatternRepository;
use App\Core\Token\TokenContext;
use App\Core\Token\TokenReplacer;
use App\Entity\Node;
use App\Entity\UrlAlias;
use App\Repository\UrlAliasRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\String\Slugger\AsciiSlugger;

/**
 * Pathauto-style automatic vanity alias, built on the EXISTING UrlAlias/UrlAliasListener
 * redirect layer instead of touching Node::slug (which stays the real canonical URL — see
 * CPALIUS_YOL_HARITASI.md T2.4 for why this is deliberate, not a shortcut).
 *
 * This sidesteps Drupal Pathauto's best-known gotcha: when a title changes and the alias
 * is regenerated, Drupal's OLD alias 404s unless the separate Redirect module is installed
 * and wired up. Here the old UrlAlias row for this node is simply left in place — every
 * UrlAlias, old or new, always resolves through UrlAliasListener::resolveNodeUrl() using the
 * node's CURRENT slug at request time, so a superseded alias keeps 301-redirecting forever
 * with zero extra configuration. Idempotent: re-running for an unchanged node is a no-op.
 */
final class PathAliasGenerator
{
    public function __construct(
        private readonly PathAliasPatternRepository $patterns,
        private readonly UrlAliasRepository $urlAliases,
        private readonly EntityManagerInterface $entityManager,
        private readonly TokenReplacer $tokenReplacer,
    ) {
    }

    /**
     * @return UrlAlias|null the alias that now exists for this node (new or already-current);
     *                       null when there is no enabled pattern for this bundle, or the
     *                       pattern resolves to an empty path. Does NOT flush.
     */
    public function generateForNode(Node $node): ?UrlAlias
    {
        $pattern = $this->patterns->findOneByTypeAndBundle('node', $node->getType());
        if ($pattern === null || !$pattern->isEnabled() || trim($pattern->getPattern()) === '') {
            return null;
        }

        $path = $this->computePath($pattern->getPattern(), $node);
        if ($path === '') {
            return null;
        }

        $current = $this->urlAliases->findOneActiveByPathAndLocale($path, $node->getLocale());
        if ($current instanceof UrlAlias && $current->getTargetType() === UrlAlias::TARGET_NODE && $current->getTargetNodeId() === $node->getId()) {
            return $current;
        }

        $uniquePath = $this->ensureUnique($path, $node->getLocale());

        $alias = new UrlAlias($uniquePath, $node->getLocale(), UrlAlias::TARGET_NODE);
        $alias->setTargetNodeId($node->getId());
        $this->entityManager->persist($alias);

        return $alias;
    }

    /**
     * Runs the pattern through TokenReplacer, then slugifies each path segment
     * independently (so "[node:title]" → "Merhaba Dünya!" becomes "merhaba-dunya",
     * while literal segments like "blog" pass through unchanged) and drops empty
     * segments (an unset [node:category] silently collapses instead of leaving
     * a double slash).
     */
    public function computePath(string $pattern, Node $node): string
    {
        $replaced = $this->tokenReplacer->replace($pattern, TokenContext::for($node));
        $slugger = new AsciiSlugger($node->getLocale());

        $segments = array_map(
            static fn (string $segment): string => strtolower($slugger->slug(trim($segment))->toString()),
            array_filter(explode('/', $replaced), static fn (string $s): bool => trim($s) !== ''),
        );

        return implode('/', array_filter($segments, static fn (string $s): bool => $s !== ''));
    }

    private function ensureUnique(string $path, string $locale): string
    {
        $candidate = $path;
        $suffix = 2;

        while ($this->urlAliases->pathExists($candidate, $locale)) {
            $candidate = $path.'-'.$suffix;
            ++$suffix;
        }

        return $candidate;
    }
}
