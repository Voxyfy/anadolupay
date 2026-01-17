<?php

declare(strict_types=1);

namespace Voxyfy\AnadoluPay\Exceptions;

use Exception;

/**
 * Driver Bulunamadı Exception
 *
 * Yapılandırılmamış veya mevcut olmayan bir ödeme geçidi driver'ı
 * kullanılmaya çalışıldığında fırlatılır. Bu exception, uygulamanın
 * ödeme işlemlerini gerçekleştirebilmesi için çözülmesi gereken
 * bir yapılandırma sorununu gösterir.
 *
 * Yaygın nedenler:
 * - İstenen driver adı yanlış yazılmış
 * - Driver, anadolupay.php'deki 'drivers' dizisine eklenmemiş
 * - Yapılandırmada belirtilen driver sınıfı mevcut değil
 * - driver() argümansız çağrıldığında varsayılan driver yapılandırılmamış
 *
 * @example
 * // 'iyzico' yapılandırılmamışsa bu exception fırlatılır:
 * try {
 *     $gateway = AnadoluPay::driver('iyzico');
 * } catch (DriverNotFoundException $e) {
 *     // Eksik driver yapılandırmasını ele al
 *     Log::error('Ödeme driver\'ı yapılandırılmamış: ' . $e->getMessage());
 * }
 */
class DriverNotFoundException extends Exception
{
    /**
     * Yeni bir Driver Bulunamadı exception instance'ı oluşturur.
     *
     * @param  string  $message  Hangi driver'ın bulunamadığını ve olası
     *                           çözümleri açıklayan exception mesajı
     * @param  int  $code  Exception kodu (varsayılan: 0)
     * @param  \Throwable|null  $previous  Exception zincirleme için önceki throwable
     */
    public function __construct(
        string $message = 'İstenen ödeme driver\'ı bulunamadı.',
        int $code = 0,
        ?\Throwable $previous = null
    ) {
        parent::__construct($message, $code, $previous);
    }
}
