<?php

declare(strict_types=1);

namespace Voxyfy\AnadoluPay\Support;

use Voxyfy\AnadoluPay\Exceptions\PaymentFailedException;
use Voxyfy\AnadoluPay\Support\Bank\Currency;

/**
 * Para Tutarı
 *
 * Tutarı her zaman **kuruş cinsinden tam sayı** olarak taşır. Ödeme
 * yolunda float aritmetiği yapılmaz: `0.1 + 0.2 !== 0.3` olduğu için
 * float ile hesaplanan bir tutar, imzaya giren dizgiyi bir kuruş
 * kaydırabilir ve banka işlemi reddeder.
 *
 * Bankalar aynı tutarı üç farklı biçimde ister; her biçimin kendi
 * dönüştürücüsü vardır:
 *
 *   19990 kuruş → minorUnits()        => "19990"   (Garanti, PosNet, Kuveyt)
 *                 toDecimalString()   => "199.90"  (Akbank POS, PayFlex, iyzico)
 *                 toNaturalString()   => "199.9"   (NestPay, PayFor)
 */
final readonly class Money
{
    /**
     * @param  int  $minorUnits  Kuruş cinsinden tutar
     * @param  string  $currency  ISO 4217 alfabetik kod
     */
    private function __construct(
        public int $minorUnits,
        public string $currency,
    ) {}

    /**
     * Kuruş cinsinden tam sayıdan tutar üretir.
     *
     * Kesinlik kaybı olmayan tek yol budur; mümkünse bunu kullanın.
     */
    public static function fromMinorUnits(int $minorUnits, string $currency = 'TRY'): self
    {
        return new self($minorUnits, strtoupper(trim($currency)));
    }

    /**
     * Ondalıklı bir tutardan (199.90) üretir.
     *
     * Float verildiğinde önce iki ondalık haneye yuvarlanır; bu, ikili
     * gösterim artıklarının (199.90000000000001) kuruşa taşmasını önler.
     *
     * @throws PaymentFailedException Tutar sayı değilse
     */
    public static function fromDecimal(float|int|string $amount, string $currency = 'TRY'): self
    {
        if (is_string($amount)) {
            $amount = str_replace(',', '.', trim($amount));

            if (! is_numeric($amount)) {
                throw new PaymentFailedException(
                    message: "Geçersiz tutar: '{$amount}'.",
                    context: ['amount' => $amount],
                );
            }
        }

        $normalized = number_format((float) $amount, 2, '.', '');

        return new self((int) str_replace('.', '', $normalized), strtoupper(trim($currency)));
    }

    /**
     * Verilen değer zaten Money ise onu, değilse ondalıklı tutar olarak
     * yorumlanmış hâlini döndürür.
     *
     * Geriye dönük uyumluluk için: paketin ilk sürümünde tutarlar float
     * olarak veriliyordu ve öyle verilmeye devam edilebilir.
     */
    public static function of(self|float|int|string $amount, string $currency = 'TRY'): self
    {
        return $amount instanceof self ? $amount : self::fromDecimal($amount, $currency);
    }

    /**
     * Sabit iki ondalıklı gösterim: "199.90".
     */
    public function toDecimalString(): string
    {
        $sign = $this->minorUnits < 0 ? '-' : '';
        $absolute = abs($this->minorUnits);

        return sprintf('%s%d.%02d', $sign, intdiv($absolute, 100), $absolute % 100);
    }

    /**
     * PHP'nin float gösterimiyle aynı doğal biçim: "199.9", "100".
     *
     * NestPay ve PayFor bu biçimi bekler. İmza gönderilen dizgi üzerinden
     * hesaplandığı için biçimin birebir korunması zorunludur.
     */
    public function toNaturalString(): string
    {
        $decimal = $this->toDecimalString();

        if (! str_contains($decimal, '.')) {
            return $decimal;
        }

        return rtrim(rtrim($decimal, '0'), '.');
    }

    /**
     * Kuruş cinsinden tutarın dizgi hâli: "19990".
     */
    public function toMinorUnitsString(): string
    {
        return (string) $this->minorUnits;
    }

    /**
     * Para biriminin ISO 4217 sayısal kodu: "949".
     */
    public function numericCurrency(): string
    {
        return Currency::numeric($this->currency);
    }

    /**
     * Aynı para biriminde yeni bir tutar üretir.
     */
    public function withAmount(int $minorUnits): self
    {
        return new self($minorUnits, $this->currency);
    }

    /**
     * Tutarın sıfır olup olmadığı.
     */
    public function isZero(): bool
    {
        return $this->minorUnits === 0;
    }

    /**
     * Loglama ve hata mesajları için okunabilir gösterim.
     */
    public function __toString(): string
    {
        return $this->toDecimalString().' '.$this->currency;
    }
}
