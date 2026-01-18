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
 * @property-read float $amount Ödeme tutarı
 * @property-read string $currency Para birimi kodu (ISO 4217)
 * @property-read string $orderId Benzersiz sipariş tanımlayıcısı
 * @property-read CustomerData $customer Müşteri bilgileri
 * @property-read string $callbackUrl Webhook bildirim URL'i
 * @property-read string $returnUrl 3D Secure sonrası yönlendirme URL'i
 * @property-read CardData|null $card Kart bilgileri (opsiyonel)
 * @property-read string|null $description Ödeme açıklaması
 * @property-read AddressData|null $billingAddress Fatura adresi
 * @property-read AddressData|null $shippingAddress Teslimat adresi
 * @property-read string|null $cancelUrl İptal yönlendirme URL'i
 * @property-read string $locale Dil kodu
 * @property-read int $installment Taksit sayısı
 * @property-read array $metadata Ek veriler
 */
readonly class CreatePaymentData
{
    /**
     * Yeni bir CreatePaymentData instance'ı oluşturur.
     *
     * @param  float  $amount  Ödeme tutarı (zorunlu, pozitif sayı)
     * @param  string  $currency  Para birimi kodu (zorunlu, örn: 'TRY')
     * @param  string  $orderId  Benzersiz sipariş ID'si (zorunlu)
     * @param  CustomerData  $customer  Müşteri bilgileri (zorunlu)
     * @param  string  $callbackUrl  Webhook URL'i (zorunlu)
     * @param  string  $returnUrl  Başarılı ödeme sonrası yönlendirme (zorunlu)
     * @param  CardData|null  $card  Kart bilgileri (hosted payment için null)
     * @param  string|null  $description  Ödeme/ürün açıklaması
     * @param  AddressData|null  $billingAddress  Fatura adresi
     * @param  AddressData|null  $shippingAddress  Teslimat adresi
     * @param  string|null  $cancelUrl  İptal durumunda yönlendirme
     * @param  string  $locale  Dil kodu (varsayılan: 'tr')
     * @param  int  $installment  Taksit sayısı (varsayılan: 1 = peşin)
     * @param  array<string, mixed>  $metadata  Sağlayıcıya özel ek veriler
     */
    public function __construct(
        public float $amount,
        public string $currency,
        public string $orderId,
        public CustomerData $customer,
        public string $callbackUrl,
        public string $returnUrl,
        public ?CardData $card = null,
        public ?string $description = null,
        public ?AddressData $billingAddress = null,
        public ?AddressData $shippingAddress = null,
        public ?string $cancelUrl = null,
        public string $locale = 'tr',
        public int $installment = 1,
        public array $metadata = [],
    ) {}

    /**
     * Array'den CreatePaymentData oluşturur.
     *
     * @param  array<string, mixed>  $data  Ödeme verileri
     */
    public static function fromArray(array $data): self
    {
        return new self(
            amount: (float) $data['amount'],
            currency: $data['currency'] ?? 'TRY',
            orderId: $data['order_id'] ?? $data['orderId'],
            customer: $data['customer'] instanceof CustomerData
                ? $data['customer']
                : CustomerData::fromArray($data['customer']),
            callbackUrl: $data['callback_url'] ?? $data['callbackUrl'],
            returnUrl: $data['return_url'] ?? $data['returnUrl'],
            card: isset($data['card'])
                ? ($data['card'] instanceof CardData ? $data['card'] : CardData::fromArray($data['card']))
                : null,
            description: $data['description'] ?? null,
            billingAddress: isset($data['billing_address'] ?? $data['billingAddress'])
                ? (($data['billing_address'] ?? $data['billingAddress']) instanceof AddressData
                    ? ($data['billing_address'] ?? $data['billingAddress'])
                    : AddressData::fromArray($data['billing_address'] ?? $data['billingAddress']))
                : null,
            shippingAddress: isset($data['shipping_address'] ?? $data['shippingAddress'])
                ? (($data['shipping_address'] ?? $data['shippingAddress']) instanceof AddressData
                    ? ($data['shipping_address'] ?? $data['shippingAddress'])
                    : AddressData::fromArray($data['shipping_address'] ?? $data['shippingAddress']))
                : null,
            cancelUrl: $data['cancel_url'] ?? $data['cancelUrl'] ?? null,
            locale: $data['locale'] ?? 'tr',
            installment: (int) ($data['installment'] ?? 1),
            metadata: $data['metadata'] ?? [],
        );
    }

    /**
     * CreatePaymentData'yı array'e dönüştürür.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'amount' => $this->amount,
            'currency' => $this->currency,
            'order_id' => $this->orderId,
            'customer' => $this->customer->toArray(),
            'callback_url' => $this->callbackUrl,
            'return_url' => $this->returnUrl,
            'card' => $this->card?->toArray(),
            'description' => $this->description,
            'billing_address' => $this->billingAddress?->toArray(),
            'shipping_address' => $this->shippingAddress?->toArray(),
            'cancel_url' => $this->cancelUrl,
            'locale' => $this->locale,
            'installment' => $this->installment,
            'metadata' => $this->metadata,
        ];
    }

    /**
     * Tutarı kuruş/cent cinsinden döndürür.
     * Bazı sağlayıcılar tutarı kuruş olarak bekler.
     *
     * @return int Kuruş cinsinden tutar
     */
    public function getAmountInMinorUnits(): int
    {
        return (int) round($this->amount * 100);
    }

    /**
     * Taksitli ödeme olup olmadığını kontrol eder.
     *
     * @return bool Taksitli ise true
     */
    public function hasInstallment(): bool
    {
        return $this->installment > 1;
    }

    /**
     * Kart bilgisi içerip içermediğini kontrol eder.
     *
     * @return bool Kart bilgisi varsa true
     */
    public function hasCard(): bool
    {
        return $this->card !== null;
    }
}
