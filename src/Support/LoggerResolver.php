<?php

declare(strict_types=1);

namespace Voxyfy\AnadoluPay\Support;

use Illuminate\Support\Facades\Log;
use Psr\Log\LoggerInterface;

/**
 * Paket genelinde log kanalını çözümler.
 *
 * Loglama varsayılan olarak kapalıdır: ödeme istekleri kart verisi taşır ve
 * maskeleme uygulansa bile bu kayıtların nereye yazıldığı bilinçli bir tercih
 * olmalıdır.
 */
final class LoggerResolver
{
    /**
     * Yapılandırma açıksa log kanalını, kapalıysa null döndürür.
     */
    public static function resolve(): ?LoggerInterface
    {
        if (! (bool) config('anadolupay.logging.enabled', false)) {
            return null;
        }

        $channel = config('anadolupay.logging.channel');

        return is_string($channel) && $channel !== ''
            ? Log::channel($channel)
            : Log::getFacadeRoot();
    }
}
