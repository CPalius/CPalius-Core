<?php

declare(strict_types=1);

namespace App\Core\Session;

use Symfony\Component\HttpFoundation\Session\Storage\Handler\NativeFileSessionHandler;
use Symfony\Component\HttpFoundation\Session\Storage\Handler\RedisSessionHandler;

/**
 * RedisSessionHandler'ı sarar: Redis'e ulaşılamadığında (bağlantı kopması,
 * MISCONF/stop-writes-on-bgsave-error gibi yazma reddi vb.) SessionHandler
 * \RedisException fırlatır ve bu, session açan HER isteği 500'e düşürür
 * (bkz. plan — bugün canlıda yaşanan RDB persistence arızası tam bu senaryo).
 * Bu sınıf her çağrıda Redis'i dener, \RedisException/\Throwable
 * yakalarsa aynı istekte NativeFileSessionHandler'a (var/sessions) düşer —
 * kullanıcı session'ını kaybeder ama site ayakta kalır.
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
        // NativeFileSessionHandler (native \SessionHandler'ı extend eder)
        // BAŞTAN open() edilmezse, read()/write() ilk kez fallback'e
        // düştüğünde PHP "Parent session handler is not open" uyarısı
        // verir. RedisSessionHandler::open() ise (AbstractSessionHandler)
        // gerçek bir TCP round-trip yapmadan her zaman true döner — asıl
        // bağlantı hatası doRead()/doWrite() içinde \RedisException olarak
        // çıkar. Bu yüzden ikisini de burada açık tutuyoruz.
        $this->primary->open($path, $name);
        $this->fallback->open($path, $name);

        return true;
    }

    public function close(): bool
    {
        // open() ile simetrik: ikisi de açıldığı için ikisi de kapatılır.
        try {
            $this->primary->close();
        } catch (\Throwable) {
            // yok sayılır — fallback zaten devrede olabilir.
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
        // NativeFileSessionHandler native \SessionHandler'ı extend eder ve
        // SessionUpdateTimestampHandlerInterface'i İMPLEMENT ETMEZ — fallback
        // tarafında validateId() yoktur, PHP native handler'larda bunu
        // read() sonucuna (boş mu değil mi) bakarak kendi içinde simüle eder.
        try {
            return $this->primary->validateId($id);
        } catch (\Throwable) {
            return '' !== $this->fallback->read($id);
        }
    }

    public function updateTimestamp(string $id, string $data): bool
    {
        // Aynı gerekçe: fallback'te updateTimestamp() yok, native handler'da
        // bunun karşılığı basitçe write()'dır.
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
