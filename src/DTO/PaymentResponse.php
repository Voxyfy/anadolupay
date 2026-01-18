<?php

declare(strict_types=1);

namespace Voxyfy\AnadoluPay\DTO;

/**
 * Ödeme Yanıtı DTO
 *
 * Ödeme başlatma isteğinin sonucunu içerir.
 * Başarılı işlemlerde yönlendirme bilgilerini,
 * başarısız işlemlerde hata detaylarını barındırır.
 *
 * @property-read bool $success Ödeme başlatmanın başarılı olup olmadığı
 * @property-read string|null $paymentId Sağlayıcıdan alınan benzersiz ödeme tanımlayıcısı
 * @property-read string|null $redirectUrl Müşteriyi ödemeyi tamamlamak için yönlendirme URL'i
 * @property-read string|null $errorMessage Başarısızlık durumunda okunabilir hata mesajı
 * @property-read array $raw Hata ayıklama için sağlayıcının ham yanıtı
 */
readonly class PaymentResponse
{
    /**
     * @param  bool  $success  Ödeme başlatmanın başarılı olup olmadığı
     * @param  string|null  $paymentId  Sağlayıcıdan alınan benzersiz ödeme tanımlayıcısı
     * @param  string|null  $redirectUrl  Müşteriyi ödemeyi tamamlamak için yönlendirme URL'i
     * @param  string|null  $errorMessage  Başarısızlık durumunda okunabilir hata mesajı
     * @param  array<string, mixed>  $raw  Hata ayıklama için sağlayıcının ham yanıtı
     */
    public function __construct(
        public bool $success,
        public ?string $paymentId = null,
        public ?string $redirectUrl = null,
        public ?string $errorMessage = null,
        public array $raw = [],
    ) {}
}
