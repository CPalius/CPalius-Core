<?php

declare(strict_types=1);

namespace App\Core\Security;

use Symfony\Component\Yaml\Yaml;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

/**
 * Roles live in cp-content/config/sync/user.role.*.yaml (versioned config), not in the DB.
 * Broken YAML / missing id / missing capabilities skip that role — never grant extra access.
 * Parsed map is stored in cache.app, keyed by a file-mtime fingerprint (PERF-03).
 */
final class RoleConfigManager
{
    private const CACHE_TTL = 3600;

    /** @var array<string, array{id: string, label: string, capabilities: list<string>}>|null */
    private ?array $roles = null;

    public function __construct(
        private readonly CapabilityRegistry $capabilityRegistry,
        private readonly string $syncDir,
        private readonly CacheInterface $cache,
    ) {
    }

    /**
     * Resolved capabilities for a role id. Missing/broken config returns [] — never throws.
     *
     * @return list<string>
     */
    public function getCapabilitiesForRole(string $roleId): array
    {
        $role = $this->loadRoles()[$roleId] ?? null;

        if ($role === null) {
            return [];
        }

        if (in_array('*', $role['capabilities'], true)) {
            return $this->capabilityRegistry->all();
        }

        return array_values(array_filter(
            $role['capabilities'],
            fn (string $capability) => $this->capabilityRegistry->has($capability),
        ));
    }

    /**
     * Union of capabilities across role ids. Unknown role ids are skipped.
     *
     * @param list<string> $roleIds
     *
     * @return list<string>
     */
    public function getCapabilitiesForRoles(array $roleIds): array
    {
        $capabilities = [];

        foreach ($roleIds as $roleId) {
            foreach ($this->getCapabilitiesForRole($roleId) as $capability) {
                $capabilities[$capability] = true;
            }
        }

        return array_keys($capabilities);
    }

    public function hasRole(string $roleId): bool
    {
        return isset($this->loadRoles()[$roleId]);
    }

    public function getLabel(string $roleId): ?string
    {
        return $this->loadRoles()[$roleId]['label'] ?? null;
    }

    /**
     * @return list<string>
     */
    public function getAllRoleIds(): array
    {
        return array_keys($this->loadRoles());
    }

    /**
     * @return array<string, array{id: string, label: string, capabilities: list<string>}>
     */
    private function loadRoles(): array
    {
        if ($this->roles !== null) {
            return $this->roles;
        }

        try {
            $fingerprint = $this->syncFingerprint();
            /** @var array<string, array{id: string, label: string, capabilities: list<string>}> $roles */
            $roles = $this->cache->get('cpalius.roles.'.$fingerprint, function (ItemInterface $item): array {
                $item->expiresAfter(self::CACHE_TTL);

                return $this->parseRoleFiles();
            });

            return $this->roles = $roles;
        } catch (\Throwable) {
            return $this->roles = $this->parseRoleFiles();
        }
    }

    /**
     * @return array<string, array{id: string, label: string, capabilities: list<string>}>
     */
    private function parseRoleFiles(): array
    {
        $roles = [];

        if (!is_dir($this->syncDir)) {
            return $roles;
        }

        foreach (glob($this->syncDir.'/user.role.*.yaml') ?: [] as $file) {
            $role = $this->parseRoleFile($file);

            if ($role !== null) {
                $roles[$role['id']] = $role;
            }
        }

        return $roles;
    }

    /**
     * @return array{id: string, label: string, capabilities: list<string>}|null
     */
    private function parseRoleFile(string $file): ?array
    {
        try {
            $data = Yaml::parseFile($file);
        } catch (\Throwable) {
            return null;
        }

        $role = $data['role'] ?? null;
        if (!is_array($role)) {
            return null;
        }

        $id = $role['id'] ?? null;
        if (!is_string($id) || $id === '') {
            return null;
        }

        $capabilities = $role['capabilities'] ?? [];
        if (!is_array($capabilities)) {
            $capabilities = [];
        }

        return [
            'id' => $id,
            'label' => is_string($role['label'] ?? null) ? $role['label'] : $id,
            'capabilities' => array_values(array_filter($capabilities, 'is_string')),
        ];
    }

    /**
     * File-mtime fingerprint so an edited YAML is a new cache key (no explicit invalidate).
     */
    private function syncFingerprint(): string
    {
        $parts = [];

        foreach (glob($this->syncDir.'/user.role.*.yaml') ?: [] as $file) {
            $parts[] = basename($file).':'.(string) @filemtime($file);
        }

        sort($parts);

        return hash('sha256', implode('|', $parts));
    }
}
