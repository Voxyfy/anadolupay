<?php

declare(strict_types=1);

namespace Voxyfy\AnadoluPay\DTO;

/**
 * Kart Verisi DTO
 *
 * Kredi/banka kartı bilgilerini güvenli bir şekilde taşır.
 * Bu sınıf readonly olarak tanımlanmıştır ve kart bilgileri
 * oluşturulduktan sonra değiştirilemez.
 *
 * GÜVENLİK UYARISI: Kart bilgileri asla loglanmamalı veya
 * veritabanında saklanmamalıdır. PCI-DSS uyumluluğu için
 * bu verilerin sadece ödeme işlemi sırasında kullanılması gerekir.
 *
 * @property-read string $holderName Kart üzerindeki isim
 * @property-read string $number Kart numarası (16 haneli)
 * @property-read string $expireMonth Son kullanma ayı (MM formatında)
 * @property-read string $expireYear Son kullanma yılı (YY veya YYYY formatında)
 * @property-read string $cvv Güvenlik kodu (3 veya 4 haneli)
 */
readonly class CardData
{
    /**
     * Yeni bir CardData instance'ı oluşturur.
     *
     * @param string $holderName Kart üzerindeki isim (zorunlu)
     * @param string $number Kart numarası (zorunlu, boşluksuz)
     * @param string $expireMonth Son kullanma ayı (zorunlu, MM formatında)
     * @param string $expireYear Son kullanma yılı (zorunlu, YY veya YYYY)
     * @param string $cvv Güvenlik kodu (zorunlu)
     */
    public function __construct(
        public string $holderName,
        public string $number,
        public string $expireMonth,
        public string $expireYear,
        public string $cvv,
    ) {}

    /**
     * Array'den CardData oluşturur.
     *
     * @param array<string, mixed> $data Kart verileri
     * @return self
     */
    public static function fromArray(array $data): self
    {
        return new self(
            holderName: $data['holder_name'] ?? $data['holderName'],
            number: self::sanitizeNumber($data['number']),
            expireMonth: str_pad((string) $data['expire_month'] ?? $data['expireMonth'], 2, '0', STR_PAD_LEFT),
            expireYear: (string) ($data['expire_year'] ?? $data['expireYear']),
            cvv: (string) $data['cvv'],
        );
    }

    /**
     * Kart numarasındaki boşluk ve tire karakterlerini temizler.
     *
     * @param string $number Ham kart numarası
     * @return string Temizlenmiş kart numarası
     */
    private static function sanitizeNumber(string $number): string
    {
        return preg_replace('/[\s\-]/', '', $number) ?? $number;
    }

    /**
     * Maskelenmiş kart numarasını döndürür.
     * Güvenlik için sadece ilk 6 ve son 4 hane gösterilir.
     *
     * @return string Maskelenmiş kart numarası (örn: 411111******1111)
     */
    public function getMaskedNumber(): string
    {
        $length = strlen($this->number);
        if ($length < 10) {
            return str_repeat('*', $length);
        }

        return substr($this->number, 0, 6) . str_repeat('*', $length - 10) . substr($this->number, -4);
    }

    /**
     * Kart numarasının geçerli olup olmadığını Luhn algoritması ile kontrol eder.
     *
     * @return bool Geçerli ise true
     */
    public function isValidNumber(): bool
    {
        $number = $this->number;
        $length = strlen($number);
        $sum = 0;
        $isSecond = false;

        for ($i = $length - 1; $i >= 0; $i--) {
            $digit = (int) $number[$i];

            if ($isSecond) {
                $digit *= 2;
                if ($digit > 9) {
                    $digit -= 9;
                }
            }

            $sum += $digit;
            $isSecond = ! $isSecond;
        }

        return $sum % 10 === 0;
    }

    /**
     * Son kullanma tarihinin geçmemiş olduğunu kontrol eder.
     *
     * @return bool Kart süresi geçmemişse true
     */
    public function isNotExpired(): bool
    {
        $year = strlen($this->expireYear) === 2
            ? (int) ('20' . $this->expireYear)
            : (int) $this->expireYear;

        $month = (int) $this->expireMonth;
        $currentYear = (int) date('Y');
        $currentMonth = (int) date('m');

        if ($year > $currentYear) {
            return true;
        }

        return $year === $currentYear && $month >= $currentMonth;
    }

    /**
     * CardData'yı array'e dönüştürür.
     *
     * GÜVENLİK UYARISI: Bu metot hassas kart bilgilerini içerir.
     * Loglama veya debug amaçlı kullanmayın.
     *
     * @return array<string, string>
     */
    public function toArray(): array
    {
        return [
            'holder_name' => $this->holderName,
            'number' => $this->number,
            'expire_month' => $this->expireMonth,
            'expire_year' => $this->expireYear,
            'cvv' => $this->cvv,
        ];
    }
}
