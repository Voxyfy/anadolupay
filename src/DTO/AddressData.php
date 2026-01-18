<?php

declare(strict_types=1);

namespace Voxyfy\AnadoluPay\DTO;

/**
 * Adres Verisi DTO
 *
 * Fatura veya teslimat adresi bilgilerini taşır.
 * Ödeme sağlayıcıları bu bilgileri dolandırıcılık kontrolü
 * ve AVS (Address Verification System) doğrulaması için kullanır.
 *
 * @property-read string $addressLine Adres satırı (sokak, mahalle, bina no vb.)
 * @property-read string $city Şehir/İlçe
 * @property-read string $country Ülke kodu (ISO 3166-1 alpha-2, örn: 'TR')
 * @property-read string|null $district İlçe/Semt
 * @property-read string|null $postalCode Posta kodu
 * @property-read string|null $contactName Alıcı adı (teslimat adresi için)
 * @property-read string|null $phone İletişim telefonu
 */
readonly class AddressData
{
    /**
     * Yeni bir AddressData instance'ı oluşturur.
     *
     * @param  string  $addressLine  Adres satırı (zorunlu)
     * @param  string  $city  Şehir (zorunlu)
     * @param  string  $country  Ülke kodu (varsayılan: 'TR')
     * @param  string|null  $district  İlçe/Semt
     * @param  string|null  $postalCode  Posta kodu
     * @param  string|null  $contactName  Alıcı adı
     * @param  string|null  $phone  İletişim telefonu
     */
    public function __construct(
        public string $addressLine,
        public string $city,
        public string $country = 'TR',
        public ?string $district = null,
        public ?string $postalCode = null,
        public ?string $contactName = null,
        public ?string $phone = null,
    ) {}

    /**
     * Array'den AddressData oluşturur.
     *
     * @param  array<string, mixed>  $data  Adres verileri
     */
    public static function fromArray(array $data): self
    {
        return new self(
            addressLine: $data['address_line'] ?? $data['addressLine'] ?? $data['address'],
            city: $data['city'],
            country: $data['country'] ?? 'TR',
            district: $data['district'] ?? null,
            postalCode: $data['postal_code'] ?? $data['postalCode'] ?? $data['zip_code'] ?? null,
            contactName: $data['contact_name'] ?? $data['contactName'] ?? null,
            phone: $data['phone'] ?? null,
        );
    }

    /**
     * AddressData'yı array'e dönüştürür.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'address_line' => $this->addressLine,
            'city' => $this->city,
            'country' => $this->country,
            'district' => $this->district,
            'postal_code' => $this->postalCode,
            'contact_name' => $this->contactName,
            'phone' => $this->phone,
        ];
    }

    /**
     * Tam adresi tek satır olarak döndürür.
     *
     * @return string Formatlı tam adres
     */
    public function getFullAddress(): string
    {
        $parts = array_filter([
            $this->addressLine,
            $this->district,
            $this->city,
            $this->postalCode,
            $this->country,
        ]);

        return implode(', ', $parts);
    }
}
