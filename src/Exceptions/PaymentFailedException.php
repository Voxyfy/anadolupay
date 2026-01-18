<?php

declare(strict_types=1);

namespace Voxyfy\AnadoluPay\Exceptions;

/**
 * Ödeme Başarısız Exception
 *
 * Sağlayıcı seviyesinde bir ödeme işlemi başarısız olduğunda fırlatılır.
 * API hataları, geçersiz istekler veya sağlayıcı reddi durumlarında kullanılır.
 */
class PaymentFailedException extends AnadoluPayException
{
    /**
     * @param  string  $message  Hata mesajı
     * @param  array<string, mixed>  $context  Hata ayıklama için ek veri
     */
    public function __construct(
        string $message = 'Payment operation failed.',
        array $context = [],
    ) {
        parent::__construct($message, $context);
    }
}
