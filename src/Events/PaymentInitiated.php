<?php

declare(strict_types=1);

namespace Voxyfy\AnadoluPay\Events;

use Voxyfy\AnadoluPay\DTO\CreatePaymentData;
use Voxyfy\AnadoluPay\DTO\PaymentResponse;
use Voxyfy\AnadoluPay\Support\Money;

/**
 * Ödeme Başlatıldı
 *
 * Müşteri bankaya yönlendirilmeden hemen önce tetiklenir. Bu noktada
 * ödeme henüz alınmamıştır; yalnızca istek hazırlanmıştır.
 *
 * Event'ler kart verisi taşımaz: dinleyicilerin çoğu bu veriyi loglar
 * veya kuyruğa yazar ve orada kart bilgisi bulunmamalıdır.
 */
final readonly class PaymentInitiated
{
    public function __construct(
        public string $driver,
        public string $orderId,
        public Money $amount,
        public string $paymentModel,
        public int $installment,
    ) {}

    public static function from(string $driver, CreatePaymentData $data, PaymentResponse $response): self
    {
        return new self(
            driver: $driver,
            orderId: $data->orderId,
            amount: $data->money(),
            paymentModel: $data->paymentModel,
            installment: $data->installments(),
        );
    }
}
