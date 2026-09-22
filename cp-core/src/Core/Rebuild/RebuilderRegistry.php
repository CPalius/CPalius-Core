<?php

declare(strict_types=1);

namespace App\Core\Rebuild;

use Symfony\Component\DependencyInjection\Attribute\TaggedIterator;

/**
 * Every rebuilder the installation knows about, keyed by id.
 *
 * Inactive modules are absent from the container, so the list shrinks on its
 * own — Core never imports Modules\*. Duplicate ids are a hard error: two jobs
 * sharing an AJAX route would clobber each other's progress.
 */
final class RebuilderRegistry
{
    /** @var array<string, RebuilderInterface>|null */
    private ?array $rebuilders = null;

    /**
     * @param iterable<RebuilderInterface> $taggedRebuilders
     */
    public function __construct(
        #[TaggedIterator('cpalius.rebuilder')]
        private readonly iterable $taggedRebuilders = [],
    ) {
    }

    public function has(string $id): bool
    {
        return isset($this->all()[$id]);
    }

    /**
     * @return array<string, RebuilderInterface>
     */
    public function forStudio(): array
    {
        return array_filter(
            $this->all(),
            static fn (RebuilderInterface $rebuilder): bool => $rebuilder->isStudioVisible(),
        );
    }

    public function get(string $id): RebuilderInterface
    {
        $rebuilder = $this->all()[$id] ?? null;

        if ($rebuilder === null) {
            throw new \InvalidArgumentException(sprintf(
                'Unknown rebuilder "%s". Known rebuilders: %s.',
                $id,
                $this->all() === [] ? '(none registered)' : implode(', ', array_keys($this->all())),
            ));
        }

        return $rebuilder;
    }

    /**
     * @return array<string, RebuilderInterface>
     */
    public function all(): array
    {
        if ($this->rebuilders !== null) {
            return $this->rebuilders;
        }

        $rebuilders = [];

        foreach ($this->taggedRebuilders as $rebuilder) {
            $id = $rebuilder->getId();

            if (isset($rebuilders[$id])) {
                throw new \LogicException(sprintf(
                    'Two rebuilders claim the id "%s" (%s and %s).',
                    $id,
                    $rebuilders[$id]::class,
                    $rebuilder::class,
                ));
            }

            $rebuilders[$id] = $rebuilder;
        }

        uasort(
            $rebuilders,
            static function (RebuilderInterface $a, RebuilderInterface $b): int {
                $priority = $a->getPriority() <=> $b->getPriority();

                return $priority !== 0 ? $priority : strcmp($a->getId(), $b->getId());
            },
        );

        return $this->rebuilders = $rebuilders;
    }
}
