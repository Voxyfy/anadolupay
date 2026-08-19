<?php

declare(strict_types=1);

namespace Voxyfy\AnadoluPay\Support;

use Voxyfy\AnadoluPay\Exceptions\PaymentFailedException;

/**
 * Sipariş Numarası Üretici
 *
 * Sipariş numarası bankada **kalıcı** bir anahtardır: aynı numara ikinci
 * kez gönderildiğinde banka işlemi reddeder ve numara iade/sorgulamada da
 * kullanıldığı için sonradan değiştirilemez. Bu yüzden numara rastgele
 * üretilir; sayaç tutmak kalıcı depolama ve kilit gerektirir, çakışması da
 * sessizce olur.
 *
 * Karakter kümesi bilinçli olarak `A-Z0-9` ile sınırlıdır: bazı bankalar
 * küçük harf ya da noktalama içeren sipariş numarasını reddeder, bir kısmı
 * da numarayı imzaya soktuğu için harf büyüklüğünü değiştirip hash'i bozar.
 */
final class OrderNumber
{
    /** Rastgele bölümde kullanılan karakterler. */
    private const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';

    /**
     * Ön ekte kabul edilen karakterler.
     *
     * Tire ve alt çizgi okunabilirlik için serbest; bunun dışındaki
     * noktalama bankalarda güvenilir biçimde geçmiyor.
     */
    private const PREFIX_PATTERN = '/^[A-Za-z0-9_-]*$/';

    /**
     * Rastgele bölümün en kısa uzunluğu.
     *
     * Bunun altında çakışma olasılığı gerçek trafikte ihmal edilebilir
     * değildir: 4 karakterde ~1.7 milyon olasılık vardır ve doğum günü
     * paradoksu yüzünden birkaç bin siparişte çakışma beklenir hâle gelir.
     */
    private const MIN_LENGTH = 6;

    /**
     * Yapılandırmadaki ön ek ve uzunlukla yeni bir sipariş numarası üretir.
     *
     * @throws PaymentFailedException Ön ek ya da uzunluk yapılandırması geçersizse
     */
    public static function generate(): string
    {
        return self::make(
            (string) config('anadolupay.order.prefix', ''),
            (int) config('anadolupay.order.length', 10),
        );
    }

    /**
     * Verilen ön ek ve uzunlukla sipariş numarası üretir.
     *
     * @throws PaymentFailedException Ön ek ya da uzunluk geçersizse
     */
    public static function make(string $prefix = '', int $length = 10): string
    {
        if (preg_match(self::PREFIX_PATTERN, $prefix) !== 1) {
            throw new PaymentFailedException(
                message: "Sipariş numarası ön eki yalnızca harf, rakam, '-' ve '_' içerebilir; '{$prefix}' verildi.",
                context: ['prefix' => $prefix],
            );
        }

        if ($length < self::MIN_LENGTH) {
            throw new PaymentFailedException(
                message: sprintf(
                    'Sipariş numarasının rastgele bölümü en az %d karakter olmalı; %d verildi.',
                    self::MIN_LENGTH,
                    $length,
                ),
                context: ['length' => $length],
            );
        }

        $max = strlen(self::ALPHABET) - 1;
        $random = '';

        for ($i = 0; $i < $length; $i++) {
            $random .= self::ALPHABET[random_int(0, $max)];
        }

        return $prefix.$random;
    }
}
