<?php

declare(strict_types=1);

namespace Voxyfy\AnadoluPay\Exceptions;

/**
 * Mükerrer Ödeme Denemesi
 *
 * Aynı sipariş numarası için koruma penceresi içinde ikinci bir ödeme
 * başlatıldığında fırlatılır. Genellikle kullanıcının "Öde" düğmesine
 * iki kez basmasından kaynaklanır.
 *
 * Bu istisnayı yakalayıp müşteriye devam eden işlemi göstermek, yeni bir
 * ödeme başlatmaktan daha doğrudur.
 */
class DuplicatePaymentException extends AnadoluPayException
{
    public function __construct(
        string $driver,
        string $orderId,
        int $windowSeconds,
    ) {
        parent::__construct(
            message: sprintf(
                "'%s' siparişi için son %d saniye içinde zaten bir ödeme başlatıldı.",
                $orderId,
                $windowSeconds,
            ),
            context: [
                'driver' => $driver,
                'order_id' => $orderId,
                'window_seconds' => $windowSeconds,
            ],
        );
    }
}
