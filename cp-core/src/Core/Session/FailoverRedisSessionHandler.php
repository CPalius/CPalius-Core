<?php

declare(strict_types=1);

namespace App\Core\Session;

use Symfony\Component\HttpFoundation\Session\Storage\Handler\NativeFileSessionHandler;
use Symfony\Component\HttpFoundation\Session\Storage\Handler\RedisSessionHandler;

/**
 * Wraps RedisSessionHandler: on Redis failure, falls back to NativeFileSessionHandler (var/sessions) so requests stay up.
 * Users may lose the session for that request, but the site does not return 500 for every session read/write.
 */
final class FailoverRedisSessionHandler implements \SessionHandlerInterface, \SessionUpdateTimestampHandlerInterface
{
    private readonly RedisSessionHandler $primary;
    private readonly NativeFileSessionHandler $fallback;

    public function __construct(RedisSessionHandler $primary, string $fallbackSavePath)
    {
        $this->primary = $primary;
        $this->fallback = new NativeFileSessionHandler($fallbackSavePath);
    }

    public function open(string $path, string $name): bool
    {
        // Open both handlers up front; Redis errors surface on read/write, not on open().
        $this->primary->open($path, $name);
        $this->fallback->open($path, $name);

        return true;
    }

    public function close(): bool
    {
        // Symmetric to open(): close both handlers; ignore primary close errors when fallback is active.
        try {
            $this->primary->close();
        } catch (\Throwable) {
        }

        return $this->fallback->close();
    }

    public function read(string $id): string|false
    {
        return $this->run(fn (): string|false => $this->primary->read($id), fn (): string|false => $this->fallback->read($id));
    }

    public function write(string $id, string $data): bool
    {
        return $this->run(fn (): bool => $this->primary->write($id, $data), fn (): bool => $this->fallback->write($id, $data));
    }

    public function destroy(string $id): bool
    {
        return $this->run(fn (): bool => $this->primary->destroy($id), fn (): bool => $this->fallback->destroy($id));
    }

    public function gc(int $max_lifetime): int|false
    {
        return $this->run(fn (): int|false => $this->primary->gc($max_lifetime), fn (): int|false => $this->fallback->gc($max_lifetime));
    }

    public function validateId(string $id): bool
    {
        // Native fallback has no validateId(); simulate it via non-empty read() result.
        try {
            return $this->primary->validateId($id);
        } catch (\Throwable) {
            return '' !== $this->fallback->read($id);
        }
    }

    public function updateTimestamp(string $id, string $data): bool
    {
        // Fallback has no updateTimestamp(); native equivalent is write().
        try {
            return $this->primary->updateTimestamp($id, $data);
        } catch (\Throwable) {
            return $this->fallback->write($id, $data);
        }
    }

    /**
     * @template T
     *
     * @param \Closure(): T $primaryCall
     * @param \Closure(): T $fallbackCall
     *
     * @return T
     */
    private function run(\Closure $primaryCall, \Closure $fallbackCall): mixed
    {
        try {
            return $primaryCall();
        } catch (\Throwable) {
            return $fallbackCall();
        }
    }
}
