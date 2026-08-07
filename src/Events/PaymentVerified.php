<?php

declare(strict_types=1);

namespace Voxyfy\AnadoluPay\Events;

use Voxyfy\AnadoluPay\DTO\VerificationResponse;

/**
 * Ödeme Doğrulandı
 *
 * Banka dönüşü doğrulanıp (gerekiyorsa) provizyon tamamlandıktan sonra
 * tetiklenir. `success` false olabilir: bu, doğrulama akışının hatasız
 * tamamlandığı ancak ödemenin alınmadığı anlamına gelir.
 */
final readonly class PaymentVerified
{
    public function __construct(
        public string $driver,
        public ?string $orderId,
        public ?string $paymentId,
        public bool $success,
        public string $status,
    ) {}

    public static function from(string $driver, VerificationResponse $response, ?string $orderId = null): self
    {
        return new self(
            driver: $driver,
            orderId: $orderId,
            paymentId: $response->paymentId,
            success: $response->success,
            status: $response->status,
        );
    }
}
