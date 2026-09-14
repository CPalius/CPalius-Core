<?php

declare(strict_types=1);

namespace App\Core\Audit\EventListener;

use App\Core\Audit\Entity\AuditLog;
use App\Core\Resource\ResourceRegistry;
use App\Core\Settings\SettingsRegistry;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Event\PostFlushEventArgs;
use Doctrine\ORM\Events;
use Doctrine\ORM\UnitOfWork;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

/**
 * Writes a cp_audit_logs row for every insert/update/delete of an entity
 * that ResourceRegistry reports as auditable (#[CpResource(auditable: true)]
 * or #[Auditable]). Fully isolated: it observes flushes and never alters
 * the audited entities themselves.
 *
 * Updates and deletes are captured in onFlush (identifiers already known)
 * and inserted into the same flush via computeChangeSet(). Creates are held
 * until postFlush so the generated primary key can be recorded, then
 * persisted with a single guarded re-flush.
 */
#[AsDoctrineListener(event: Events::onFlush)]
#[AsDoctrineListener(event: Events::postFlush)]
final class AuditLogListener
{
    /** @var list<array{0: AuditLog, 1: object}> pending create logs and their entities */
    private array $pendingCreateLogs = [];

    private bool $flushingLogs = false;

    /**
     * Memoised audit.enabled. Resolved once per request, not per flush: this
     * runs inside Doctrine's flush cycle, and issuing a settings query on every
     * write would put a SELECT in front of every INSERT in the application.
     */
    private ?bool $enabled = null;

    public function __construct(
        private readonly ResourceRegistry $resourceRegistry,
        private readonly TokenStorageInterface $tokenStorage,
        private readonly SettingsRegistry $settings,
    ) {
    }

    /**
     * Whether the audit engine should record at all.
     *
     * Fails OPEN — an unreadable settings table means "keep recording", never
     * "stop". The failure mode of an audit trail that silently switched itself
     * off is one nobody notices until they need the trail.
     */
    private function isEnabled(): bool
    {
        if ($this->enabled !== null) {
            return $this->enabled;
        }

        try {
            return $this->enabled = (bool) $this->settings->get('audit.enabled', true);
        } catch (\Throwable) {
            return $this->enabled = true;
        }
    }

    public function onFlush(OnFlushEventArgs $args): void
    {
        if ($this->flushingLogs) {
            return;
        }

        // Checked here, at the write path, rather than only in the purge task:
        // a disabled engine must stop PRODUCING rows, not produce them and
        // delete them later.
        if (!$this->isEnabled()) {
            return;
        }

        $em = $args->getObjectManager();
        $unitOfWork = $em->getUnitOfWork();

        $auditableClasses = $this->resourceRegistry->getAuditableEntityClasses();
        if ($auditableClasses === []) {
            return;
        }

        $userId = $this->currentUserId();
        $auditMetadata = $em->getClassMetadata(AuditLog::class);
        $immediateLogs = [];

        foreach ($unitOfWork->getScheduledEntityInsertions() as $entity) {
            if (!$this->isAuditable($em, $entity, $auditableClasses)) {
                continue;
            }

            $log = new AuditLog(
                $this->resolveResourceName($em, $entity),
                null,
                AuditLog::ACTION_CREATE,
                $this->buildInsertChanges($unitOfWork, $entity),
                $userId,
            );

            $this->pendingCreateLogs[] = [$log, $entity];
        }

        foreach ($unitOfWork->getScheduledEntityUpdates() as $entity) {
            if (!$this->isAuditable($em, $entity, $auditableClasses)) {
                continue;
            }

            $changes = $this->buildUpdateChanges($unitOfWork, $entity);
            if ($changes === []) {
                continue;
            }

            $immediateLogs[] = new AuditLog(
                $this->resolveResourceName($em, $entity),
                $this->resolveResourceId($em, $entity),
                AuditLog::ACTION_UPDATE,
                $changes,
                $userId,
            );
        }

        foreach ($unitOfWork->getScheduledEntityDeletions() as $entity) {
            if (!$this->isAuditable($em, $entity, $auditableClasses)) {
                continue;
            }

            $immediateLogs[] = new AuditLog(
                $this->resolveResourceName($em, $entity),
                $this->resolveResourceId($em, $entity),
                AuditLog::ACTION_DELETE,
                $this->buildDeleteSnapshot($unitOfWork, $entity),
                $userId,
            );
        }

        foreach ($immediateLogs as $log) {
            $em->persist($log);
            $unitOfWork->computeChangeSet($auditMetadata, $log);
        }
    }

    public function postFlush(PostFlushEventArgs $args): void
    {
        if ($this->flushingLogs || $this->pendingCreateLogs === []) {
            return;
        }

        $pending = $this->pendingCreateLogs;
        $this->pendingCreateLogs = [];

        $em = $args->getObjectManager();

        foreach ($pending as [$log, $entity]) {
            $log->setResourceId($this->resolveResourceId($em, $entity));
            $em->persist($log);
        }

        $this->flushingLogs = true;

        try {
            $em->flush();
        } finally {
            $this->flushingLogs = false;
        }
    }

    /**
     * @param list<class-string> $auditableClasses
     */
    private function isAuditable(EntityManagerInterface $em, object $entity, array $auditableClasses): bool
    {
        if ($entity instanceof AuditLog) {
            return false;
        }

        return \in_array($this->realClass($em, $entity), $auditableClasses, true);
    }

    private function realClass(EntityManagerInterface $em, object $entity): string
    {
        return $em->getClassMetadata($entity::class)->getName();
    }

    private function resolveResourceName(EntityManagerInterface $em, object $entity): string
    {
        $class = $this->realClass($em, $entity);

        $definition = $this->resourceRegistry->get($class);
        if ($definition !== null && $definition->name !== '') {
            return $definition->name;
        }

        $segments = explode('\\', $class);

        return (string) end($segments);
    }

    private function resolveResourceId(EntityManagerInterface $em, object $entity): ?string
    {
        $identifiers = $em->getClassMetadata($entity::class)->getIdentifierValues($entity);
        if ($identifiers === []) {
            return null;
        }

        $flat = array_map(
            fn (mixed $value): string => (string) $this->scalarize($value),
            $identifiers,
        );

        $joined = implode('-', $flat);

        return $joined !== '' ? $joined : null;
    }

    /**
     * @return array<string, array{0: mixed, 1: mixed}>
     */
    private function buildInsertChanges(UnitOfWork $unitOfWork, object $entity): array
    {
        $changes = [];

        foreach ($unitOfWork->getEntityChangeSet($entity) as $field => $pair) {
            $new = $this->scalarize($pair[1] ?? null);
            if ($new === null) {
                continue;
            }

            $changes[$field] = [null, $new];
        }

        return $changes;
    }

    /**
     * @return array<string, array{0: mixed, 1: mixed}>
     */
    private function buildUpdateChanges(UnitOfWork $unitOfWork, object $entity): array
    {
        $changes = [];

        foreach ($unitOfWork->getEntityChangeSet($entity) as $field => $pair) {
            $old = $this->scalarize($pair[0] ?? null);
            $new = $this->scalarize($pair[1] ?? null);

            if ($old === $new) {
                continue;
            }

            $changes[$field] = [$old, $new];
        }

        return $changes;
    }

    /**
     * @return array<string, array{0: mixed, 1: mixed}>
     */
    private function buildDeleteSnapshot(UnitOfWork $unitOfWork, object $entity): array
    {
        $snapshot = [];

        foreach ($unitOfWork->getOriginalEntityData($entity) as $field => $value) {
            $old = $this->scalarize($value);
            if ($old === null) {
                continue;
            }

            $snapshot[$field] = [$old, null];
        }

        return $snapshot;
    }

    /**
     * Reduces any change-set value to something JSON can store safely.
     */
    private function scalarize(mixed $value): mixed
    {
        if ($value === null || \is_scalar($value)) {
            return $value;
        }

        if ($value instanceof \DateTimeInterface) {
            return $value->format(\DateTimeInterface::ATOM);
        }

        if ($value instanceof \BackedEnum) {
            return $value->value;
        }

        if (\is_array($value)) {
            return $value;
        }

        if (\is_object($value) && method_exists($value, 'getId')) {
            return $this->scalarize($value->getId());
        }

        return \is_object($value) ? $value::class : (string) $value;
    }

    private function currentUserId(): ?int
    {
        $user = $this->tokenStorage->getToken()?->getUser();

        return $user instanceof User ? $user->getId() : null;
    }
}
