<?php

declare(strict_types=1);

namespace Voxyfy\AnadoluPay\Events;

use Throwable;

/**
 * Ödeme Başarısız
 *
 * Akış bir istisnayla kesildiğinde tetiklenir: banka isteği reddetti,
 * imza doğrulanamadı ya da bankaya ulaşılamadı.
 *
 * `exception` alanı ayrımı taşır — `TransportException` ise sonucun
 * belirsiz olduğunu, `PaymentFailedException` ise bankanın kesin olarak
 * reddettiğini gösterir.
 */
final readonly class PaymentFailed
{
    public function __construct(
        public string $driver,
        public ?string $orderId,
        public string $reason,
        public Throwable $exception,
    ) {}

    public static function from(string $driver, ?string $orderId, Throwable $exception): self
    {
        return new self(
            driver: $driver,
            orderId: $orderId,
            reason: $exception->getMessage(),
            exception: $exception,
        );
    }
}
