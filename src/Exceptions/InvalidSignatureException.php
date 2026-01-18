<?php

declare(strict_types=1);

namespace Voxyfy\AnadoluPay\Exceptions;

/**
 * Geçersiz İmza Exception
 *
 * Webhook veya callback imza doğrulaması başarısız olduğunda fırlatılır.
 */
class InvalidSignatureException extends AnadoluPayException
{
    /**
     * @param  string  $driver  Driver adı
     * @param  array<string, mixed>  $context  Hata ayıklama için ek veri
     */
    public function __construct(
        string $driver,
        array $context = [],
    ) {
        parent::__construct(
            message: "Invalid callback signature for driver '{$driver}'.",
            context: array_merge(['driver' => $driver], $context),
        );
    }
}
