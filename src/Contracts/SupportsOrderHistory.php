<?php

declare(strict_types=1);

namespace Voxyfy\AnadoluPay\Contracts;

/**
 * Sipariş Hareketlerini Sorgulayabilen Driver
 *
 * Durum sorgusu siparişin *güncel* hâlini verir; hareket dökümü ise o
 * sipariş üzerinde yapılmış tüm işlemleri (satış, iptal, kısmi iade,
 * provizyon kapama) sırasıyla listeler.
 *
 * Mutabakat ve uyuşmazlık incelemelerinde asıl ihtiyaç duyulan budur:
 * "bu sipariş iade edildi mi" değil, "ne zaman ne kadar iade edildi".
 */
interface SupportsOrderHistory
{
    /**
     * Siparişin hareket dökümünü döndürür.
     *
     * Yanıt sağlayıcıya göre değiştiği için normalleştirilmez; bankanın
     * ham hareket listesi olduğu gibi verilir.
     *
     * @param  array<string, mixed>  $context  Sağlayıcıya özel ek alanlar
     * @return array<string, mixed>
     */
    public function orderHistory(string $orderId, array $context = []): array;
}
