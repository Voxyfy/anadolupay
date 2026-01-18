<?php

declare(strict_types=1);

namespace Voxyfy\AnadoluPay\Exceptions;

/**
 * Driver Bulunamadı Exception
 *
 * İstenen ödeme driver'ı kayıtlı veya yapılandırılmış değilse fırlatılır.
 */
class DriverNotFoundException extends AnadoluPayException
{
    /**
     * @param  string  $driver  Bulunamayan driver adı
     * @param  array<int, string>  $availableDrivers  Mevcut driver listesi
     */
    public function __construct(
        string $driver,
        array $availableDrivers = [],
    ) {
        parent::__construct(
            message: "Payment driver '{$driver}' is not registered.",
            context: [
                'driver' => $driver,
                'available_drivers' => $availableDrivers,
            ],
        );
    }
}
