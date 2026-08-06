<?php

declare(strict_types=1);

namespace Voxyfy\AnadoluPay\DTO;

use Voxyfy\AnadoluPay\Exceptions\PaymentFailedException;

/**
 * Kredi Kartı Verisi DTO
 *
 * Banka sanal POS'ları kart verisini doğrudan istek gövdesinde veya
 * 3D Secure formunda beklediği için kart bilgisi birinci sınıf bir
 * DTO olarak modellenmiştir.
 *
 * Bu nesne asla loglanmamalı veya kalıcı olarak saklanmamalıdır.
 */
final readonly class CardData
{
    /**
     * @param  string  $number  Kart numarası (boşluk/tire olmadan)
     * @param  string  $expireMonth  Son kullanma ayı, 2 hane (örn: 01)
     * @param  string  $expireYear  Son kullanma yılı, 2 veya 4 hane (örn: 30 veya 2030)
     * @param  string  $cvv  Güvenlik kodu
     * @param  string|null  $holderName  Kart sahibinin adı soyadı
     * @param  string|null  $type  Kart tipi (visa, mastercard, troy, amex) — bazı bankalarda zorunlu
     */
    public function __construct(
        public string $number,
        public string $expireMonth,
        public string $expireYear,
        public string $cvv,
        public ?string $holderName = null,
        public ?string $type = null,
    ) {}

    /**
     * Dizi gösteriminden kart nesnesi üretir.
     *
     * Hem `expireMonth`/`expireYear` hem de `expire_month`/`expire_year`
     * anahtarlarını kabul eder.
     *
     * @param  array<string, mixed>  $card
     *
     * @throws PaymentFailedException Zorunlu alanlar eksikse
     */
    public static function fromArray(array $card): self
    {
        $number = self::pull($card, ['number', 'cardNumber', 'card_number']);
        $month = self::pull($card, ['expireMonth', 'expire_month', 'month']);
        $year = self::pull($card, ['expireYear', 'expire_year', 'year']);
        $cvv = self::pull($card, ['cvv', 'cvc', 'cvv2']);

        foreach (['number' => $number, 'expireMonth' => $month, 'expireYear' => $year, 'cvv' => $cvv] as $field => $value) {
            if ($value === null || $value === '') {
                throw new PaymentFailedException("Kart bilgisi eksik: '{$field}' alanı zorunludur.");
            }
        }

        return new self(
            number: preg_replace('/\D/', '', (string) $number) ?? '',
            expireMonth: str_pad((string) $month, 2, '0', STR_PAD_LEFT),
            expireYear: (string) $year,
            cvv: (string) $cvv,
            holderName: self::pull($card, ['holderName', 'holder_name', 'cardHolderName', 'card_holder_name', 'name']),
            type: self::pull($card, ['type', 'cardType', 'card_type']),
        );
    }

    /**
     * Son kullanma yılını 2 haneli döndürür (örn: 30).
     */
    public function expireYearShort(): string
    {
        return substr($this->expireYear, -2);
    }

    /**
     * Son kullanma yılını 4 haneli döndürür (örn: 2030).
     */
    public function expireYearLong(): string
    {
        $short = $this->expireYearShort();

        return strlen($this->expireYear) === 4 ? $this->expireYear : '20'.$short;
    }

    /**
     * Son kullanma tarihini istenen formatta döndürür.
     *
     * Desteklenen formatlar PHP date() söz dizimindedir; örn:
     * 'my' => 0130, 'm/y' => 01/30, 'ym' => 3001, 'Ym' => 203001.
     */
    public function expiry(string $format): string
    {
        return strtr($format, [
            'm' => $this->expireMonth,
            'y' => $this->expireYearShort(),
            'Y' => $this->expireYearLong(),
        ]);
    }

    /**
     * Kart numarasını maskeler (loglama için güvenli gösterim).
     */
    public function masked(): string
    {
        $length = strlen($this->number);

        if ($length <= 10) {
            return str_repeat('*', $length);
        }

        return substr($this->number, 0, 6).str_repeat('*', $length - 10).substr($this->number, -4);
    }

    /**
     * @param  array<string, mixed>  $card
     * @param  list<string>  $keys
     */
    private static function pull(array $card, array $keys): ?string
    {
        foreach ($keys as $key) {
            if (isset($card[$key]) && $card[$key] !== '') {
                return (string) $card[$key];
            }
        }

        return null;
    }
}
