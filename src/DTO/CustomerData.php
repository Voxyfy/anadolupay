<?php

declare(strict_types=1);

namespace Voxyfy\AnadoluPay\DTO;

/**
 * Müşteri Verisi DTO
 *
 * Ödeme işlemi sırasında müşteri hakkında gerekli bilgileri taşır.
 * Bu bilgiler, ödeme sağlayıcıları tarafından dolandırıcılık kontrolü
 * ve fatura oluşturma amacıyla kullanılır.
 *
 * @property-read string $name Müşteri adı soyadı
 * @property-read string $email Müşteri e-posta adresi
 * @property-read string $ipAddress Müşteri IP adresi (zorunlu - dolandırıcılık kontrolü için)
 * @property-read string|null $phone Müşteri telefon numarası
 * @property-read string|null $identityNumber TC Kimlik veya Vergi numarası
 * @property-read string|null $city Şehir
 * @property-read string|null $country Ülke kodu (ISO 3166-1 alpha-2, örn: 'TR')
 */
readonly class CustomerData
{
    /**
     * Yeni bir CustomerData instance'ı oluşturur.
     *
     * @param  string  $name  Müşteri adı soyadı (zorunlu)
     * @param  string  $email  Müşteri e-posta adresi (zorunlu)
     * @param  string  $ipAddress  Müşteri IP adresi (zorunlu)
     * @param  string|null  $phone  Müşteri telefon numarası
     * @param  string|null  $identityNumber  TC Kimlik veya Vergi numarası
     * @param  string|null  $city  Şehir
     * @param  string|null  $country  Ülke kodu (ISO 3166-1 alpha-2)
     */
    public function __construct(
        public string $name,
        public string $email,
        public string $ipAddress,
        public ?string $phone = null,
        public ?string $identityNumber = null,
        public ?string $city = null,
        public ?string $country = 'TR',
    ) {}

    /**
     * Array'den CustomerData oluşturur.
     *
     * @param  array<string, mixed>  $data  Müşteri verileri
     */
    public static function fromArray(array $data): self
    {
        return new self(
            name: $data['name'],
            email: $data['email'],
            ipAddress: $data['ip_address'] ?? $data['ipAddress'],
            phone: $data['phone'] ?? null,
            identityNumber: $data['identity_number'] ?? $data['identityNumber'] ?? null,
            city: $data['city'] ?? null,
            country: $data['country'] ?? 'TR',
        );
    }

    /**
     * CustomerData'yı array'e dönüştürür.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'email' => $this->email,
            'ip_address' => $this->ipAddress,
            'phone' => $this->phone,
            'identity_number' => $this->identityNumber,
            'city' => $this->city,
            'country' => $this->country,
        ];
    }
}
