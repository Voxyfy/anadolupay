<?php

declare(strict_types=1);

namespace Voxyfy\AnadoluPay\DTO;

/**
 * Ödeme Oluşturma Verisi DTO
 *
 * Yeni bir ödeme işlemi başlatmak için gerekli tüm bilgileri içerir.
 * Bu DTO, farklı ödeme sağlayıcılarına gönderilecek verileri
 * standart bir formatta tutar.
 *
 * @property-read float $amount Ödeme tutarı (örn: 199.99)
 * @property-read string $currency ISO para birimi kodu (örn: TRY, USD)
 * @property-read string $orderId Satıcı sistemindeki benzersiz sipariş referansı
 * @property-read array $customer Müşteri bilgileri (isim, email, telefon vb.)
 * @property-read string $successUrl Başarılı ödeme sonrası yönlendirme URL'i
 * @property-read string $failUrl Başarısız ödeme sonrası yönlendirme URL'i
 * @property-read array $metadata Dahili kullanım için anahtar-değer verileri
 */
readonly class CreatePaymentData
{
    /**
     * @param  float  $amount  Ödeme tutarı (örn: 199.99)
     * @param  string  $currency  ISO para birimi kodu (örn: TRY, USD)
     * @param  string  $orderId  Satıcı sistemindeki benzersiz sipariş referansı
     * @param  array<string, mixed>  $customer  Müşteri bilgileri (isim, email, telefon vb.)
     * @param  string  $successUrl  Başarılı ödeme sonrası yönlendirme URL'i
     * @param  string  $failUrl  Başarısız ödeme sonrası yönlendirme URL'i
     * @param  array<string, mixed>  $metadata  Dahili kullanım için anahtar-değer verileri
     */
    public function __construct(
        public float $amount,
        public string $currency,
        public string $orderId,
        public array $customer,
        public string $successUrl,
        public string $failUrl,
        public array $metadata = [],
    ) {}
}
