<?php

declare(strict_types=1);

namespace Voxyfy\AnadoluPay\DTO;

/**
 * İade Verisi DTO
 *
 * Bir ödemenin tamamen veya kısmen iade edilmesi için
 * gerekli verileri içerir. Kısmi iade desteği sunar.
 *
 * @property-read string $paymentId Sağlayıcıya özel ödeme tanımlayıcısı
 * @property-read float|null $amount İade tutarı (null = tam iade)
 * @property-read string|null $reason İsteğe bağlı iade nedeni
 */
readonly class RefundPaymentData
{
    /**
     * @param  string  $paymentId  Sağlayıcıya özel ödeme tanımlayıcısı
     * @param  float|null  $amount  İade tutarı (null = tam iade)
     * @param  string|null  $reason  İsteğe bağlı iade nedeni
     */
    public function __construct(
        public string $paymentId,
        public ?float $amount = null,
        public ?string $reason = null,
    ) {}
}
