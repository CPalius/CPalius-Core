<?php

declare(strict_types=1);

namespace App\Core\Config\Provider;

use App\Core\Config\ConfigProviderInterface;
use App\Core\PathAlias\Entity\PathAliasPattern;
use App\Core\PathAlias\Repository\PathAliasPatternRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * One document per bundle: path_pattern.{bundle}. Authoritative — deleting the file (or the
 * key) removes the DB row, which only stops FUTURE auto-generation; it never touches content
 * or already-generated UrlAlias rows (those keep redirecting regardless of the pattern).
 */
final class PathAliasPatternConfigProvider implements ConfigProviderInterface
{
    private const PREFIX = 'path_pattern.';

    private const ENTITY_TYPE = 'node';

    public function __construct(
        private readonly PathAliasPatternRepository $repository,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function documents(): array
    {
        return array_map(
            static fn (string $bundle): string => self::PREFIX.$bundle,
            $this->repository->distinctBundles(self::ENTITY_TYPE),
        );
    }

    public function ownsDocument(string $name): bool
    {
        return str_starts_with($name, self::PREFIX) && $this->bundleOf($name) !== '';
    }

    public function exportDocument(string $name): array
    {
        $pattern = $this->repository->findOneByTypeAndBundle(self::ENTITY_TYPE, $this->bundleOf($name));
        if ($pattern === null) {
            return [];
        }

        return [
            'pattern' => $pattern->getPattern(),
            'enabled' => $pattern->isEnabled(),
        ];
    }

    public function diffDocument(string $name, array $incoming): array
    {
        $bundle = $this->bundleOf($name);
        $live = $this->repository->findOneByTypeAndBundle(self::ENTITY_TYPE, $bundle);
        $incomingPattern = (string) ($incoming['pattern'] ?? '');
        $incomingEnabled = (bool) ($incoming['enabled'] ?? true);

        if ($live === null) {
            return $incomingPattern !== '' ? [sprintf('+ %s', $bundle)] : [];
        }

        if ($live->getPattern() !== $incomingPattern || $live->isEnabled() !== $incomingEnabled) {
            return [sprintf('~ %s', $bundle)];
        }

        return [];
    }

    public function importDocument(string $name, array $incoming): array
    {
        $bundle = $this->bundleOf($name);
        $pattern = (string) ($incoming['pattern'] ?? '');
        $enabled = (bool) ($incoming['enabled'] ?? true);

        if ($pattern === '') {
            $existing = $this->repository->findOneByTypeAndBundle(self::ENTITY_TYPE, $bundle);
            if ($existing !== null) {
                $this->entityManager->remove($existing);

                return [sprintf('removed %s', $bundle)];
            }

            return [];
        }

        $live = $this->repository->findOneByTypeAndBundle(self::ENTITY_TYPE, $bundle);
        if ($live === null) {
            $live = new PathAliasPattern(self::ENTITY_TYPE, $bundle, $pattern);
            $live->setEnabled($enabled);
            $this->entityManager->persist($live);

            return [sprintf('created %s', $bundle)];
        }

        $live->setPattern($pattern)->setEnabled($enabled);

        return [sprintf('updated %s', $bundle)];
    }

    public function afterImport(): void
    {
    }

    private function bundleOf(string $document): string
    {
        $bundle = substr($document, \strlen(self::PREFIX));

        return preg_match('/^[a-z][a-z0-9_-]{0,49}$/', $bundle) === 1 ? $bundle : '';
    }
}
