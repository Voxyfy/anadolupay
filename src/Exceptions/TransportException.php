<?php

declare(strict_types=1);

namespace Voxyfy\AnadoluPay\Exceptions;

use Throwable;

/**
 * Taşıma Katmanı Hatası
 *
 * Bankanın iş kuralı reddiyle (yetersiz bakiye, geçersiz kart) taşıma
 * katmanı hatasını (bağlantı kurulamadı, 502 döndü) ayırmak için vardır.
 * Bu ayrım kozmetik değildir:
 *
 *   - `PaymentFailedException` kesin bir cevaptır: banka isteği aldı ve
 *     reddetti. Tekrar denemek anlamsızdır.
 *   - `TransportException` ise **belirsizdir**: isteğin bankaya ulaşıp
 *     ulaşmadığı, ulaştıysa işlenip işlenmediği bilinmez.
 *
 * Belirsizliğin sonucu şudur: bir ödeme isteği zaman aşımına uğradığında
 * körlemesine tekrar denemek çift çekime yol açabilir. `$safeToRetry`
 * bu ayrımı taşır ve yalnızca isteğin bankaya **ulaşmadığından** emin
 * olunan durumlarda true olur.
 */
class TransportException extends AnadoluPayException
{
    /**
     * @param  string  $message  Hata mesajı
     * @param  bool  $safeToRetry  İsteğin bankaya ulaşmadığı kesin mi
     * @param  array<string, mixed>  $context  Hata ayıklama verisi
     */
    public function __construct(
        string $message,
        public readonly bool $safeToRetry = false,
        array $context = [],
        int $code = 0,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, $context, $code, $previous);
    }
}
