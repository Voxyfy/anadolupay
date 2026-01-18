<?php

declare(strict_types=1);

namespace Voxyfy\AnadoluPay\Exceptions;

use RuntimeException;
use Throwable;

/**
 * AnadoluPay Temel Exception
 *
 * Tüm AnadoluPay exception'larının extend ettiği temel sınıf.
 * Hata ayıklama ve loglama için opsiyonel context verisi destekler.
 */
class AnadoluPayException extends RuntimeException
{
    /**
     * @param  string  $message  Hata mesajı
     * @param  array<string, mixed>  $context  Hata ayıklama için ek veri
     * @param  int  $code  Hata kodu
     * @param  Throwable|null  $previous  Önceki exception
     */
    public function __construct(
        string $message,
        public readonly array $context = [],
        int $code = 0,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, $code, $previous);
    }
}
