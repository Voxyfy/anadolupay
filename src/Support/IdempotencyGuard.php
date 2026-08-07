<?php

declare(strict_types=1);

namespace Voxyfy\AnadoluPay\Support;

use Illuminate\Support\Facades\Cache;
use Voxyfy\AnadoluPay\Exceptions\DuplicatePaymentException;

/**
 * Mükerrer Ödeme Koruması
 *
 * Aynı sipariş numarası için kısa bir pencere içinde ikinci bir ödeme
 * başlatılmasını engeller. Asıl hedef, kullanıcının "Öde" düğmesine iki
 * kez basması veya tarayıcının isteği tekrarlaması gibi kazaları
 * yakalamaktır.
 *
 * Pencere bilinçli olarak kısadır. Ödeme gerçekten başarısız olduğunda
 * müşterinin aynı sipariş numarasıyla tekrar denemesi meşrudur; uzun bir
 * kilit bu akışı bozar. Varsayılan 30 saniye, çift tıklamayı yakalamaya
 * yeter ama meşru yeniden denemeyi engellemez.
 *
 * Kilit `Cache::add()` ile alınır; bu işlem atomiktir, yani iki eşzamanlı
 * istekten yalnızca biri geçer. Bunun çalışması için `array` dışında bir
 * cache sürücüsü (redis, memcached, database) gerekir.
 *
 * Bu koruma bir kolaylıktır, kesin bir garanti değildir. Mükerrer çekime
 * karşı asıl savunma, siparişin durumunu kendi veritabanınızda tutmak ve
 * ödeme alınmış siparişler için akışı hiç başlatmamaktır.
 */
class IdempotencyGuard
{
    public function __construct(
        private readonly bool $enabled = false,
        private readonly int $ttlSeconds = 30,
        private readonly ?string $store = null,
        private readonly string $prefix = 'anadolupay:payment:',
    ) {}

    /**
     * Yapılandırmadan koruma nesnesi üretir.
     */
    public static function fromConfig(): self
    {
        $config = config('anadolupay.idempotency', []);

        return new self(
            enabled: (bool) ($config['enabled'] ?? false),
            ttlSeconds: (int) ($config['ttl'] ?? 30),
            store: is_string($config['store'] ?? null) && $config['store'] !== '' ? $config['store'] : null,
            prefix: (string) ($config['prefix'] ?? 'anadolupay:payment:'),
        );
    }

    /**
     * Sipariş için kilidi almaya çalışır.
     *
     * @throws DuplicatePaymentException Kilit zaten alınmışsa
     */
    public function acquire(string $driver, string $orderId): void
    {
        if (! $this->enabled) {
            return;
        }

        $key = $this->key($driver, $orderId);

        // Cache::add() yalnızca anahtar yoksa yazar ve sonucu atomik döndürür.
        if (! Cache::store($this->store)->add($key, true, $this->ttlSeconds)) {
            throw new DuplicatePaymentException($driver, $orderId, $this->ttlSeconds);
        }
    }

    /**
     * Kilidi bırakır.
     *
     * Ödeme başlatma isteği hata aldığında çağrılır: başarısız bir denemenin
     * müşteriyi pencere boyunca kilitlemesi için sebep yoktur.
     */
    public function release(string $driver, string $orderId): void
    {
        if (! $this->enabled) {
            return;
        }

        Cache::store($this->store)->forget($this->key($driver, $orderId));
    }

    private function key(string $driver, string $orderId): string
    {
        return $this->prefix.$driver.':'.$orderId;
    }
}
