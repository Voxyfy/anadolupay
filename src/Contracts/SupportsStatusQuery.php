<?php

declare(strict_types=1);

namespace Voxyfy\AnadoluPay\Contracts;

use Voxyfy\AnadoluPay\DTO\StatusResponse;
use Voxyfy\AnadoluPay\Exceptions\TransportException;

/**
 * Sipariş Durumu Sorgulayabilen Driver
 *
 * Bu arayüz, ödeme akışının en kritik kurtarma yolunu tanımlar. Bir istek
 * zaman aşımına uğradığında (`TransportException`) paranın çekilip
 * çekilmediği bilinmez; bunu öğrenmenin tek yolu bankaya sormaktır.
 *
 * Her sağlayıcı durum sorgusu sunmaz. Yeteneği çalışma zamanında
 * kontrol edebilirsiniz:
 *
 *     $gateway = AnadoluPay::driver('garanti');
 *
 *     if ($gateway instanceof SupportsStatusQuery) {
 *         $status = $gateway->status('SIPARIS-123');
 *     }
 */
interface SupportsStatusQuery
{
    /**
     * Siparişin bankadaki güncel durumunu sorgular.
     *
     * @param  string  $orderId  Satıcı sipariş numarası
     * @param  array<string, mixed>  $context  Bankaya özel ek alanlar. Bazı
     *                                         bankalar sorgu için sipariş
     *                                         numarası dışında bir referans
     *                                         ister (örn. PayFlex
     *                                         `transaction_id`, PosNet
     *                                         `payment_model`).
     *
     * @throws TransportException Bankaya ulaşılamazsa
     */
    public function status(string $orderId, array $context = []): StatusResponse;
}
