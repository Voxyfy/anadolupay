<?php

declare(strict_types=1);

namespace Voxyfy\AnadoluPay\Exceptions;

/**
 * Desteklenmeyen İşlem Exception
 *
 * Sağlayıcı istenen işlemi desteklemiyorsa fırlatılır.
 * Örnek: iade, kısmi iade, taksit desteği.
 */
class UnsupportedOperationException extends AnadoluPayException
{
    /**
     * @param  string  $operation  Desteklenmeyen işlem adı
     * @param  string  $driver  Driver adı
     */
    public function __construct(
        string $operation,
        string $driver,
    ) {
        parent::__construct(
            message: "Operation '{$operation}' is not supported by driver '{$driver}'.",
            context: [
                'operation' => $operation,
                'driver' => $driver,
            ],
        );
    }
}
