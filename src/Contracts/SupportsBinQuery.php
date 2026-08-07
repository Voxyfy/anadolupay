<?php

declare(strict_types=1);

namespace Voxyfy\AnadoluPay\Contracts;

use Voxyfy\AnadoluPay\DTO\BinResponse;

/**
 * BIN Sorgusu Yapabilen Driver
 *
 * Kartın ilk hanelerinden bankasını, markasını ve tipini öğrenmeyi
 * sağlar. Ödeme sayfasında taksit seçeneklerini kart girilirken
 * göstermek için kullanılır.
 *
 * Her sağlayıcı sunmaz; Garanti, iyzico, Param ve PayTR destekler.
 */
interface SupportsBinQuery
{
    /**
     * BIN bilgisini sorgular.
     *
     * @param  string  $bin  Kart numarasının ilk 6-8 hanesi.
     *                       Kart numarasının tamamını göndermeyin.
     * @param  array<string, mixed>  $context  Sağlayıcıya özel ek alanlar
     */
    public function binLookup(string $bin, array $context = []): BinResponse;
}
