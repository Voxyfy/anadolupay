<?php

declare(strict_types=1);

namespace Voxyfy\AnadoluPay\DTO;

/**
 * İade Yanıtı DTO
 *
 * İade işleminin sonucunu içerir. Başarılı iadelerde
 * iade referans bilgilerini, başarısız iadelerde
 * hata detaylarını barındırır.
 *
 * @property-read bool $success İadenin başarılı olup olmadığı
 * @property-read string|null $refundId Sağlayıcıdan alınan iade tanımlayıcısı
 * @property-read string|null $errorMessage İade başarısız olduysa hata mesajı
 * @property-read array $raw Sağlayıcının ham yanıtı
 */
readonly class RefundResponse
{
    /**
     * @param  bool  $success  İadenin başarılı olup olmadığı
     * @param  string|null  $refundId  Sağlayıcıdan alınan iade tanımlayıcısı
     * @param  string|null  $errorMessage  İade başarısız olduysa hata mesajı
     * @param  array<string, mixed>  $raw  Sağlayıcının ham yanıtı
     */
    public function __construct(
        public bool $success,
        public ?string $refundId = null,
        public ?string $errorMessage = null,
        public array $raw = [],
    ) {}
}
