<?php

declare(strict_types=1);

namespace Voxyfy\AnadoluPay\Exceptions;

use Throwable;

/**
 * Bankaya Ulaşılamadı
 *
 * Bağlantı kurulamadı, DNS çözülemedi, TLS el sıkışması başarısız oldu
 * veya istek zaman aşımına uğradı.
 *
 * Bu hataların çoğunda istek bankaya hiç ulaşmaz ve tekrar denemek
 * güvenlidir. **Zaman aşımı istisnadır**: istek bankaya ulaşmış, işlenmiş
 * ve yalnızca yanıt geri dönememiş olabilir. Bu yüzden zaman aşımı
 * `safeToRetry: false` olarak işaretlenir — sonucunu bilmeden ikinci bir
 * ödeme isteği göndermek çift çekim demektir.
 */
class GatewayUnreachableException extends TransportException
{
    /**
     * @param  array<string, mixed>  $context
     */
    public static function connectionFailed(
        string $bank,
        string $url,
        array $context = [],
        ?Throwable $previous = null,
    ): self {
        return new self(
            message: sprintf("'%s' bankasına bağlanılamadı.", $bank),
            safeToRetry: true,
            context: $context + ['bank' => $bank, 'url' => $url, 'reason' => 'connection_failed'],
            previous: $previous,
            // Bağlantı hiç kurulamadı: istek bankaya ulaşmadı, sonuç kesin.
            outcomeUncertain: false,
        );
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public static function timedOut(
        string $bank,
        string $url,
        int $timeout,
        array $context = [],
        ?Throwable $previous = null,
    ): self {
        return new self(
            message: sprintf(
                "'%s' bankası %d saniyede yanıt vermedi. İşlemin gerçekleşip gerçekleşmediği belirsizdir; durum sorgusuyla teyit edin.",
                $bank,
                $timeout,
            ),
            // Zaman aşımında istek bankaya ulaşmış olabilir.
            safeToRetry: false,
            context: $context + ['bank' => $bank, 'url' => $url, 'timeout' => $timeout, 'reason' => 'timeout'],
            previous: $previous,
        );
    }
}
