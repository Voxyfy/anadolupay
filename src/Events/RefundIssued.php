<?php

declare(strict_types=1);

namespace Voxyfy\AnadoluPay\Events;

use Voxyfy\AnadoluPay\DTO\RefundPaymentData;
use Voxyfy\AnadoluPay\DTO\RefundResponse;
use Voxyfy\AnadoluPay\Support\Money;

/**
 * İade İşlendi
 *
 * İade isteği bankaya gönderildikten sonra tetiklenir. `success` false
 * olabilir; banka iadeyi reddetmiş olabilir.
 */
final readonly class RefundIssued
{
    public function __construct(
        public string $driver,
        public string $paymentId,
        public ?Money $amount,
        public ?string $refundId,
        public bool $success,
    ) {}

    public static function from(string $driver, RefundPaymentData $data, RefundResponse $response): self
    {
        return new self(
            driver: $driver,
            paymentId: $data->paymentId,
            amount: $data->money(),
            refundId: $response->refundId,
            success: $response->success,
        );
    }
}
