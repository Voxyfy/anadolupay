<?php

declare(strict_types=1);

namespace Voxyfy\AnadoluPay\Support\Bank;

use Voxyfy\AnadoluPay\DTO\StatusResponse;

/**
 * Banka Durum Kodları Sözlüğü
 *
 * Her banka sipariş durumunu farklı kodlarla bildirir: NestPay tek harf
 * (`A`, `V`, `PN`), Kuveyt Türk sayı (`1`, `4`, `6`), Param büyük harfli
 * dizgi (`SUCCESS`, `PARTIAL_REFUND`), Akbank ise Türkçe kelime
 * (`Başarılı`, `İptal`). Bu sınıf hepsini `StatusResponse` sabitlerine
 * indirger.
 *
 * Tanınmayan kodlar `STATUS_UNKNOWN` döner; sessizce "başarılı" saymak
 * yerine bilinmediğini söylemek doğru davranıştır.
 */
final class OrderStatus
{
    /** Asseco / NestPay durum kodları. */
    public const NESTPAY = [
        'A' => StatusResponse::STATUS_PAID,
        'C' => StatusResponse::STATUS_PAID,
        'S' => StatusResponse::STATUS_PAID,
        'PN' => StatusResponse::STATUS_PENDING,
        'CNCL' => StatusResponse::STATUS_CANCELLED,
        'V' => StatusResponse::STATUS_CANCELLED,
        'D' => StatusResponse::STATUS_FAILED,
        'ERR' => StatusResponse::STATUS_FAILED,
    ];

    /** Garanti sipariş durumları. */
    public const GARANTI = [
        'APPROVED' => StatusResponse::STATUS_PAID,
        'VOID' => StatusResponse::STATUS_CANCELLED,
        'CANCELLED' => StatusResponse::STATUS_CANCELLED,
        'REFUNDED' => StatusResponse::STATUS_REFUNDED,
        'DECLINED' => StatusResponse::STATUS_FAILED,
        'ERROR' => StatusResponse::STATUS_FAILED,
        'WAITINGPOSTAUTH' => StatusResponse::STATUS_PRE_AUTHORIZED,
    ];

    /** Kuveyt Türk ve Vakıf Katılım (BOA) durum kodları. */
    public const BOA = [
        '1' => StatusResponse::STATUS_PAID,
        '4' => StatusResponse::STATUS_REFUNDED,
        '5' => StatusResponse::STATUS_REFUNDED,
        '6' => StatusResponse::STATUS_CANCELLED,
    ];

    /** Tosla durum kodları. */
    public const TOSLA = [
        '0' => StatusResponse::STATUS_FAILED,
        '1' => StatusResponse::STATUS_PAID,
        '2' => StatusResponse::STATUS_CANCELLED,
        '3' => StatusResponse::STATUS_REFUNDED,
        '4' => StatusResponse::STATUS_REFUNDED,
        '5' => StatusResponse::STATUS_PRE_AUTHORIZED,
    ];

    /** Param durum kodları. */
    public const PARAM = [
        'SUCCESS' => StatusResponse::STATUS_PAID,
        'FAIL' => StatusResponse::STATUS_FAILED,
        'BANK_FAIL' => StatusResponse::STATUS_FAILED,
        'CANCEL' => StatusResponse::STATUS_CANCELLED,
        'REFUND' => StatusResponse::STATUS_REFUNDED,
        'PARTIAL_REFUND' => StatusResponse::STATUS_REFUNDED,
    ];

    /** Akbank POS durum kodları (işlem geçmişinde Türkçe döner). */
    public const AKBANK = [
        'N' => StatusResponse::STATUS_PAID,
        'S' => StatusResponse::STATUS_FAILED,
        'V' => StatusResponse::STATUS_CANCELLED,
        'R' => StatusResponse::STATUS_REFUNDED,
        'BAŞARILI' => StatusResponse::STATUS_PAID,
        'BAŞARISIZ' => StatusResponse::STATUS_FAILED,
        'İPTAL' => StatusResponse::STATUS_CANCELLED,
    ];

    /** iyzico ödeme durumları. */
    public const IYZICO = [
        'SUCCESS' => StatusResponse::STATUS_PAID,
        'FAILURE' => StatusResponse::STATUS_FAILED,
        'INIT_THREEDS' => StatusResponse::STATUS_PENDING,
        'CALLBACK_THREEDS' => StatusResponse::STATUS_PENDING,
        'CALLBACK_PECCO' => StatusResponse::STATUS_PENDING,
        'BKM_POS_PENDING' => StatusResponse::STATUS_PENDING,
    ];

    /**
     * Craftgate ödeme durumları.
     *
     * Craftgate iadeyi ödemenin durumunda değil ayrı bir iade kaydında
     * tutar; bu yüzden sözlükte `refunded` karşılığı yoktur.
     */
    public const CRAFTGATE = [
        'SUCCESS' => StatusResponse::STATUS_PAID,
        'FAILURE' => StatusResponse::STATUS_FAILED,
        'INIT_THREEDS' => StatusResponse::STATUS_PENDING,
        'CALLBACK_THREEDS' => StatusResponse::STATUS_PENDING,
        'WAITING' => StatusResponse::STATUS_PENDING,
    ];

    /**
     * Moka `PaymentStatus` kodları.
     *
     * Bu kod tek başına yeterli değildir: işlemin başarılı olup olmadığını
     * `TrxStatus` söyler. Driver önce onu kontrol eder.
     */
    public const MOKA = [
        '0' => StatusResponse::STATUS_PENDING,
        '1' => StatusResponse::STATUS_PRE_AUTHORIZED,
        '2' => StatusResponse::STATUS_PAID,
        '3' => StatusResponse::STATUS_CANCELLED,
        '4' => StatusResponse::STATUS_REFUNDED,
    ];

    /**
     * Paratika işlem durumları.
     *
     * `MR` (manuel inceleme) kasıtlı olarak `pending`dir: işlem henüz
     * sonuçlanmamıştır, başarılı da başarısız da sayılamaz.
     */
    public const PARATIKA = [
        'AP' => StatusResponse::STATUS_PAID,
        'FA' => StatusResponse::STATUS_FAILED,
        'VD' => StatusResponse::STATUS_CANCELLED,
        'CA' => StatusResponse::STATUS_CANCELLED,
        'IP' => StatusResponse::STATUS_PENDING,
        'MR' => StatusResponse::STATUS_PENDING,
    ];

    /**
     * Banka kodunu normalleştirilmiş duruma çevirir.
     *
     * PHP sayısal dizgi anahtarlarını tam sayıya çevirdiği için sözlükler
     * hem `string` hem `int` anahtar taşıyabilir.
     *
     * @param  array<array-key, string>  $dictionary  self::NESTPAY gibi bir sözlük
     */
    public static function map(?string $code, array $dictionary): string
    {
        if ($code === null || $code === '') {
            return StatusResponse::STATUS_UNKNOWN;
        }

        return $dictionary[mb_strtoupper(trim($code))]
            ?? $dictionary[trim($code)]
            ?? StatusResponse::STATUS_UNKNOWN;
    }
}
