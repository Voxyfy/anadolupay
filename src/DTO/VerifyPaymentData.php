<?php

declare(strict_types=1);

namespace Voxyfy\AnadoluPay\DTO;

/**
 * Ödeme Doğrulama Verisi DTO
 *
 * Ödeme sağlayıcısından gelen callback/webhook isteğini doğrulamak
 * için gerekli verileri içerir. İmza doğrulaması ve ödeme
 * durumu kontrolü için kullanılır.
 *
 * @property-read array $payload Ödeme sağlayıcısı tarafından gönderilen istek verisi
 * @property-read array $headers Callback/webhook isteğiyle gönderilen HTTP başlıkları
 * @property-read string|null $rawBody İmza doğrulaması için ham istek gövdesi
 */
readonly class VerifyPaymentData
{
    /**
     * @param  array<string, mixed>  $payload  Ödeme sağlayıcısı tarafından gönderilen istek verisi
     * @param  array<string, mixed>  $headers  Callback/webhook isteğiyle gönderilen HTTP başlıkları
     * @param  string|null  $rawBody  İmza doğrulaması için ham istek gövdesi
     */
    public function __construct(
        public array $payload,
        public array $headers = [],
        public ?string $rawBody = null,
    ) {}
}
