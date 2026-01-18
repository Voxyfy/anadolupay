<?php

declare(strict_types=1);

namespace Voxyfy\AnadoluPay\DTO;

/**
 * Ödeme Doğrulama Yanıtı DTO
 *
 * Ödeme doğrulama isteğinin sonucunu içerir.
 * Ödemenin başarılı olup olmadığını ve normalleştirilmiş
 * durum bilgisini barındırır.
 *
 * @property-read bool $success Ödeme doğrulamasının başarılı olup olmadığı
 * @property-read string|null $paymentId Doğrulanan ödeme tanımlayıcısı
 * @property-read string $status Normalleştirilmiş ödeme durumu (success, failed, pending)
 * @property-read array $raw Sağlayıcının ham yanıtı
 */
readonly class VerificationResponse
{
    /**
     * @param  bool  $success  Ödeme doğrulamasının başarılı olup olmadığı
     * @param  string|null  $paymentId  Doğrulanan ödeme tanımlayıcısı
     * @param  string  $status  Normalleştirilmiş ödeme durumu (success, failed, pending)
     * @param  array<string, mixed>  $raw  Sağlayıcının ham yanıtı
     */
    public function __construct(
        public bool $success,
        public ?string $paymentId,
        public string $status,
        public array $raw = [],
    ) {}
}
