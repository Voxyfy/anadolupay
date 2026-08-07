<?php

declare(strict_types=1);

namespace Voxyfy\AnadoluPay\DTO;

use Voxyfy\AnadoluPay\Support\Money;

/**
 * Provizyon Kapama Verisi
 *
 * Ön provizyon (pre-auth) kartta tutarı bloke eder ama tahsil etmez.
 * Tahsilat, provizyon kapama (post-auth / capture) ile yapılır.
 *
 * Kapama tutarı ön provizyondan **küçük veya eşit** olabilir; büyük
 * olamaz. Otel ve araç kiralama gibi nihai tutarı sonradan belli olan
 * senaryoların tipik kullanımı budur: yüksek bloke, düşük tahsilat.
 */
final readonly class CapturePaymentData
{
    /**
     * @param  string  $orderId  Ön provizyonun sipariş numarası
     * @param  float|Money|null  $amount  Tahsil edilecek tutar; null ise
     *                                    bloke edilen tutarın tamamı
     * @param  string  $currency  ISO para birimi kodu
     * @param  array<string, mixed>  $metadata  Bankaya özel referanslar
     *                                          (Garanti `ref_ret_num`,
     *                                          PayFlex `transaction_id`,
     *                                          PosNet `host_ref_num`)
     * @param  string|null  $ip  Müşteri IP adresi
     */
    public function __construct(
        public string $orderId,
        public float|Money|null $amount = null,
        public string $currency = 'TRY',
        public array $metadata = [],
        public ?string $ip = null,
    ) {}

    /**
     * Tahsil edilecek tutarı `Money` olarak döndürür; tam kapamada null.
     */
    public function money(): ?Money
    {
        return $this->amount === null ? null : Money::of($this->amount, $this->currency);
    }

    /**
     * Bankaya özel ek alanı okur.
     */
    public function meta(string $key, mixed $default = null): mixed
    {
        return $this->metadata[$key] ?? $default;
    }

    public function clientIp(): string
    {
        return $this->ip !== null && $this->ip !== '' ? $this->ip : '127.0.0.1';
    }
}
