<?php

declare(strict_types=1);

namespace App\Tests\Unit\Core\Storage;

use App\Core\Storage\RemoteTargetInterface;
use App\Core\Storage\StorageException;
use App\Core\Storage\TargetResolverInterface;

/**
 * An in-memory bucket, so offload and backup-shipping behaviour can be asserted
 * without a network.
 *
 * It can be told to fail a specific operation, because the interesting cases in
 * both callers are the failures: media offload has to swallow them and carry
 * on, and backup shipping has to refuse to delete the local copy.
 */
final class FakeTarget implements RemoteTargetInterface, TargetResolverInterface
{
    /** @var array<string, string> remote key => body */
    public array $objects = [];

    /** @var list<string> */
    public array $puts = [];

    public bool $failPut = false;

    /** Simulates a PUT that returns success for a body that never arrived. */
    public bool $swallowPut = false;

    public bool $failDelete = false;

    public function __construct(
        private readonly string $type = 's3',
        private readonly ?string $selected = null,
    ) {
    }

    // -- RemoteTargetInterface ------------------------------------------------

    public function type(): string
    {
        return $this->type;
    }

    public function put(string $localPath, string $remoteKey): void
    {
        $this->puts[] = $remoteKey;

        if ($this->failPut) {
            throw new StorageException('put refused');
        }

        if ($this->swallowPut) {
            return;
        }

        $this->objects[$remoteKey] = (string) file_get_contents($localPath);
    }

    public function has(string $remoteKey): bool
    {
        return isset($this->objects[$remoteKey]);
    }

    public function delete(string $remoteKey): void
    {
        if ($this->failDelete) {
            throw new StorageException('delete refused');
        }

        unset($this->objects[$remoteKey]);
    }

    public function test(): void
    {
    }

    public function describe(): string
    {
        return $this->type.'://fake';
    }

    // -- TargetResolverInterface ---------------------------------------------

    public function resolveFor(string $purpose): ?RemoteTargetInterface
    {
        return $this->selected === 'off' ? null : $this;
    }

    public function selectedType(string $purpose): string
    {
        return $this->selected ?? $this->type;
    }
}
