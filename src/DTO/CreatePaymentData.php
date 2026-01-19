<?php

declare(strict_types=1);

namespace Voxyfy\AnadoluPay\DTO;

/**
 * Ödeme Oluşturma Verisi DTO
 *
 * Ödeme sağlayıcılarından bağımsız, yeni bir ödeme işlemi
 * başlatmak için gerekli temel verileri içerir.
 */
final readonly class CreatePaymentData
{
    /**
     * @param float $amount Ödeme tutarı (örn: 199.99)
     * @param string $currency ISO para birimi kodu (örn: TRY, USD)
     * @param string $orderId Satıcı sistemindeki benzersiz sipariş referansı
     * @param array<string, mixed> $customer Müşteri bilgileri
     * @param string|null $successUrl Başarılı ödeme sonrası yönlendirme URL'i (opsiyonel)
     * @param string|null $failUrl Başarısız ödeme sonrası yönlendirme URL'i (opsiyonel)
     * @param array<string, mixed> $metadata Sağlayıcıya özel veya dahili ek veriler
     */
    public function __construct(
        public float $amount,
        public string $currency,
        public string $orderId,
        public array $customer,
        public ?string $successUrl = null,
        public ?string $failUrl = null,
        public array $metadata = [],
    ) {}
}
