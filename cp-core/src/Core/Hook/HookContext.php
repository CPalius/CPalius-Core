<?php

declare(strict_types=1);

namespace App\Core\Hook;

/**
 * Bir kanca (hook) noktasına gönderilen ve o noktadan geri alınan verinin
 * tek taşıyıcısı. Cotonti'nin klasik "$extra" değişken çantası ile aynı
 * ruhta: hook'u tetikleyen taraf istediği anahtarları set() eder, dinleyen
 * her hook (flat-file veya attribute) aynı $context üzerinde okuma/yazma
 * yapar, HookManager::trigger() zincirin sonunda güncellenmiş context'i
 * çağıran tarafa geri döner.
 *
 * Fluent interface BİLİNÇLİ olarak tercih edildi: hem çekirdek içinde
 * (`(new HookContext())->set('node', $node)->set('request', $request)`)
 * hem de flat-file hook dosyaları içinde ($context->set(...)->get(...))
 * zincirleme çağrı okunabilirliği artırır.
 */
final class HookContext
{
    /** @var array<string, mixed> */
    private array $data = [];

    /**
     * @param array<string, mixed> $initial
     */
    public function __construct(array $initial = [])
    {
        $this->data = $initial;
    }

    public function set(string $key, mixed $value): self
    {
        $this->data[$key] = $value;

        return $this;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->data[$key] ?? $default;
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->data);
    }

    public function remove(string $key): self
    {
        unset($this->data[$key]);

        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function all(): array
    {
        return $this->data;
    }

    /**
     * Twig kanca noktalarının (cp_hook()) konvansiyonu: her dinleyici,
     * ürettiği HTML parçasını doğrudan return ETMEK yerine bu metotla
     * context'e "ekler". Birden fazla dinleyici aynı noktaya bağlıysa
     * (ör. hem flat-file hem attribute kulvarından), çıktılar sırayla
     * BİRİKTİRİLİR — sonraki bir dinleyici öncekinin çıktısını asla
     * ezmez. HookRuntime::render() son halini burada okur.
     */
    public function appendHtml(string $html): self
    {
        $this->data['__html_output'] = ($this->data['__html_output'] ?? '').$html;

        return $this;
    }

    public function getHtml(): string
    {
        $value = $this->data['__html_output'] ?? '';

        return is_string($value) ? $value : '';
    }
}
