<?php

namespace App\Core\Security;

use Symfony\Component\Yaml\Yaml;
use Throwable;

/**
 * Rolleri DB'de DEĞİL, cp-content/config/sync/user.role.*.yaml
 * dosyalarında saklarız — Content (kullanıcılar, node'lar) DB'de kalırken,
 * Config (roller, yapılandırma) dosya sisteminde ve versiyon kontrolünde
 * kalır. Böylece bir rolün yetenekleri, ortamdan ortama (dev/staging/prod)
 * git ile taşınabilir; DB migration/seed gerekmez.
 *
 * Fail-Safe: bozuk bir YAML dosyası, geçersiz bir "role.id" ya da eksik
 * "capabilities" alanı sessizce ATLANIR (o rol hiç var olmamış gibi
 * davranılır) — asla bir hata tüm rol çözümlemesini durdurmaz veya
 * (daha kötüsü) o role belirsiz/geniş bir yetki vermez.
 */
final class RoleConfigManager
{
    /** @var array<string, array{id: string, label: string, capabilities: list<string>}>|null */
    private ?array $roles = null;

    public function __construct(
        private readonly CapabilityRegistry $capabilityRegistry,
        private readonly string $syncDir,
    ) {
    }

    /**
     * Verilen rol kimliğinin ("admin", "editor") gerçek, ÇÖZÜLMÜŞ
     * (fail-safe filtrelenmiş) yetenek listesini döner. Rol config'i
     * yoksa veya bozuksa boş dizi döner — asla exception fırlatmaz.
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
     * Birden çok rolün (bir kullanıcının sahip olduğu tüm roller) birleşik
     * yetenek kümesini döner. Tanımsız/bozuk bir rol kimliği listede varsa
     * o rol sessizce yok sayılır, diğerleri normal çözülmeye devam eder.
     *
     * @param list<string> $roleIds
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

        $this->roles = [];

        if (!is_dir($this->syncDir)) {
            return $this->roles;
        }

        foreach (glob($this->syncDir.'/user.role.*.yaml') ?: [] as $file) {
            $role = $this->parseRoleFile($file);

            if ($role !== null) {
                $this->roles[$role['id']] = $role;
            }
        }

        return $this->roles;
    }

    /**
     * @return array{id: string, label: string, capabilities: list<string>}|null
     */
    private function parseRoleFile(string $file): ?array
    {
        try {
            $data = Yaml::parseFile($file);
        } catch (Throwable) {
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
}
