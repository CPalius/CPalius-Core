<?php

declare(strict_types=1);

namespace App\Core\Trash;

use App\Core\Annotation\NotTrashable;
use App\Core\Resource\ResourceRegistry;
use App\Core\Trash\Entity\TrashRule;
use Doctrine\DBAL\Connection;

/**
 * Decides, per resource type, whether a deleted row is kept and for how long.
 *
 * Read through DBAL rather than the ORM because it is consulted from inside
 * Doctrine's onFlush, where starting another ORM query is asking for trouble.
 * Resolved once per request: a SELECT in front of every delete would be paid by
 * every write path in the application.
 */
final class TrashPolicy
{
    public const DEFAULT_RETENTION_DAYS = 30;

    /**
     * Namespaces whose rows are never worth restoring — the trash itself and
     * append-only or high-churn tables. Deleting a log row is housekeeping,
     * not a mistake somebody wants undone.
     *
     * @var list<string>
     */
    private const EXCLUDED_NAMESPACES = [
        'App\\Core\\Trash\\',
        'App\\Core\\Audit\\',
        'App\\Core\\Logging\\',
        'App\\Core\\Queue\\',
        'App\\Core\\Api\\Entity\\',
        'App\\Core\\Security\\Entity\\',
        'App\\Core\\Revision\\',
        'App\\Core\\Migrate\\Entity\\',
        'App\\Core\\Notification\\',
        'App\\Core\\Mail\\Entity\\',
    ];

    /** @var array<string, array{enabled: bool, days: int}>|null */
    private ?array $rules = null;

    private bool $suspended = false;

    public function __construct(
        private readonly Connection $connection,
        private readonly ResourceRegistry $resourceRegistry,
    ) {
    }

    /**
     * Whether deletes of $class are captured at all (class-level, rule-independent).
     *
     * @param class-string $class
     */
    public function isTrashableClass(string $class): bool
    {
        foreach (self::EXCLUDED_NAMESPACES as $prefix) {
            if (str_starts_with($class, $prefix)) {
                return false;
            }
        }

        return (new \ReflectionClass($class))->getAttributes(NotTrashable::class) === [];
    }

    /**
     * Type name shown in the bin: the #[CpResource] name, else the short class name.
     *
     * @param class-string $class
     */
    public function resourceName(string $class): string
    {
        $definition = $this->resourceRegistry->get($class);
        if ($definition !== null && $definition->name !== '') {
            return $definition->name;
        }

        $segments = explode('\\', $class);

        return (string) end($segments);
    }

    public function isEnabled(string $resourceName): bool
    {
        $rules = $this->rules();

        if (!($rules[TrashRule::GLOBAL]['enabled'] ?? true)) {
            return false;
        }

        return $rules[$resourceName]['enabled'] ?? true;
    }

    /**
     * 0 = never expires automatically.
     */
    public function retentionDays(string $resourceName): int
    {
        $rules = $this->rules();

        return $rules[$resourceName]['days']
            ?? $rules[TrashRule::GLOBAL]['days']
            ?? self::DEFAULT_RETENTION_DAYS;
    }

    public function expiryFor(string $resourceName): ?\DateTimeImmutable
    {
        $days = $this->retentionDays($resourceName);

        return $days > 0 ? (new \DateTimeImmutable())->modify(\sprintf('+%d days', $days)) : null;
    }

    public function reset(): void
    {
        $this->rules = null;
    }

    /**
     * Runs $work with capture off: a permanent delete of an already-binned row must not drop a
     * fresh copy of it back into the bin.
     */
    public function withoutCapture(callable $work): void
    {
        $this->suspended = true;
        try {
            $work();
        } finally {
            $this->suspended = false;
        }
    }

    public function isSuspended(): bool
    {
        return $this->suspended;
    }

    /**
     * @return array<string, array{enabled: bool, days: int}>
     */
    private function rules(): array
    {
        if ($this->rules !== null) {
            return $this->rules;
        }

        $this->rules = [];

        try {
            $rows = $this->connection->fetchAllAssociative('SELECT resource_name, enabled, retention_days FROM cp_trash_rules');
        } catch (\Throwable) {
            // Table not migrated yet. Capturing would fail on the INSERT into
            // cp_trash_items anyway, so behave as "off" until it exists.
            return $this->rules = [TrashRule::GLOBAL => ['enabled' => false, 'days' => self::DEFAULT_RETENTION_DAYS]];
        }

        foreach ($rows as $row) {
            $this->rules[(string) $row['resource_name']] = [
                'enabled' => (bool) $row['enabled'],
                'days' => (int) $row['retention_days'],
            ];
        }

        return $this->rules;
    }
}
