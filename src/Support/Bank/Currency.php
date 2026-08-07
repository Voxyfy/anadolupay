<?php

declare(strict_types=1);

namespace Voxyfy\AnadoluPay\Support\Bank;

use Voxyfy\AnadoluPay\Exceptions\PaymentFailedException;

/**
 * ISO 4217 Para Birimi Kodları
 *
 * Türk banka sanal POS'ları para birimini alfabetik kod yerine
 * sayısal ISO 4217 kodu ile bekler (örn: TRY => 949).
 */
final class Currency
{
    /**
     * Alfabetik koddan sayısal ISO 4217 koduna eşleme.
     *
     * @var array<string, string>
     */
    private const NUMERIC = [
        'TRY' => '949',
        'USD' => '840',
        'EUR' => '978',
        'GBP' => '826',
        'JPY' => '392',
        'RUB' => '643',
        'CHF' => '756',
        'CAD' => '124',
        'AUD' => '036',
        'SAR' => '682',
        'AED' => '784',
        'CNY' => '156',
    ];

    /**
     * Alfabetik kodu sayısal ISO 4217 koduna çevirir.
     *
     * @throws PaymentFailedException Para birimi desteklenmiyorsa
     */
    public static function numeric(string $currency): string
    {
        $code = strtoupper(trim($currency));

        if (! isset(self::NUMERIC[$code])) {
            throw new PaymentFailedException(
                message: "Desteklenmeyen para birimi: '{$currency}'.",
                context: ['supported' => self::supported()],
            );
        }

        return self::NUMERIC[$code];
    }

    /**
     * Sayısal ISO 4217 kodunu alfabetik koda çevirir.
     *
     * Bankalar kodu sıfırla doldurulmuş gönderebilir (Kuveyt Türk '0949'
     * kullanır); baştaki sıfırlar yok sayılır.
     *
     * Bilinmeyen kodlarda girdiyi olduğu gibi döndürür: yanıt eşlemesinde
     * hata fırlatmak, yalnızca para birimi tanınmadığı için başarılı bir
     * ödemenin kaybedilmesine yol açardı.
     */
    public static function alphabetic(string $numeric): string
    {
        $normalized = ltrim(trim($numeric), '0');

        if ($normalized === '') {
            return $numeric;
        }

        $found = array_search($normalized, self::NUMERIC, true);

        return is_string($found) ? $found : $numeric;
    }

    /**
     * Desteklenen alfabetik para birimi kodları.
     *
     * @return list<string>
     */
    public static function supported(): array
    {
        return array_keys(self::NUMERIC);
    }
}
