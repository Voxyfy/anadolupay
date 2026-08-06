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
     * @param  array<string, mixed>  $metadata  Sağlayıcıya özel ek alanlar
     * @param  string  $currency  İade para birimi (banka sanal POS'ları zorunlu tutar)
     */
    public function __construct(
        public string $paymentId,
        public ?float $amount = null,
        public ?string $reason = null,
        public array $metadata = [],
        public string $currency = 'TRY',
    ) {}

    /**
     * Sağlayıcıya özel ek alanı okur.
     *
     * Bazı bankalar iade için sipariş numarası dışında bir referans bekler;
     * örneğin Garanti `ref_ret_num`, PosNet `host_ref_num` ister.
     */
    public function meta(string $key, mixed $default = null): mixed
    {
        return $this->metadata[$key] ?? $default;
    }
}
