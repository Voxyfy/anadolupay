<?php

declare(strict_types=1);

namespace Voxyfy\AnadoluPay\Contracts;

use Voxyfy\AnadoluPay\DTO\InstallmentOption;
use Voxyfy\AnadoluPay\Support\Money;

/**
 * Taksit Seçeneklerini Sorgulayabilen Driver
 *
 * Komisyon oranları bankaya, karta ve kampanyaya göre değişir ve
 * zamanla güncellenir. Sabit bir tablo tutmak yerine sorgulamak,
 * müşteriye yanlış tutar göstermeyi engeller.
 *
 * Her sağlayıcı sunmaz; PayTR, Param, Tosla ve iyzico destekler.
 */
interface SupportsInstallmentQuery
{
    /**
     * Verilen tutar için taksit seçeneklerini döndürür.
     *
     * @param  Money  $amount  İşlem tutarı
     * @param  string|null  $bin  Kartın ilk haneleri; verilirse yalnızca
     *                            o kartın seçenekleri döner
     * @return list<InstallmentOption>
     */
    public function installmentOptions(Money $amount, ?string $bin = null): array;
}
