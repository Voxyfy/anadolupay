<?php

declare(strict_types=1);

namespace Voxyfy\AnadoluPay\Facades;

use Illuminate\Support\Facades\Facade;
use Voxyfy\AnadoluPay\Contracts\PaymentGatewayInterface;

/**
 * AnadoluPay Facade
 *
 * AnadoluPay ödeme geçidi yöneticisine statik bir arayüz sağlar.
 * Bu facade, Laravel uygulamanız boyunca AnadoluPay instance'ını
 * enjekte etmeden ödeme geçidi işlevlerine kolay erişim sağlar.
 *
 * @method static PaymentGatewayInterface driver(string|null $name = null) Ödeme geçidi driver instance'ı döndürür
 * @method static string|null getDefaultDriver() Varsayılan driver adını döndürür
 * @method static array|null getDriverConfig(string $name) Belirli bir driver için yapılandırmayı döndürür
 * @method static array getAvailableDrivers() Tüm kayıtlı driver adlarını döndürür
 * @method static bool hasDriver(string $name) Driver'ın yapılandırılıp yapılandırılmadığını kontrol eder
 *
 * @see \Voxyfy\AnadoluPay\AnadoluPay
 *
 * @example
 * // Varsayılan ödeme geçidi driver'ını al
 * $gateway = AnadoluPay::driver();
 *
 * // Belirli bir ödeme geçidi driver'ını al
 * $gateway = AnadoluPay::driver('iyzico');
 *
 * // Varsayılan driver ile ödeme oluştur
 * $sonuc = AnadoluPay::driver()->createPayment($odemeVerisi);
 *
 * // Mevcut driver'ları kontrol et
 * $drivers = AnadoluPay::getAvailableDrivers();
 */
class AnadoluPay extends Facade
{
    /**
     * Bileşenin kayıtlı adını döndürür.
     *
     * Bu facade'in proxy yaptığı service container bağlama anahtarını
     * döndürür. AnadoluPay sınıfı, service provider'da singleton
     * olarak kaydedilmiştir.
     *
     * @return string Service container bağlama anahtarı
     */
    protected static function getFacadeAccessor(): string
    {
        return \Voxyfy\AnadoluPay\AnadoluPay::class;
    }
}
