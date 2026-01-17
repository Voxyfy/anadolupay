<?php

declare(strict_types=1);

namespace Voxyfy\AnadoluPay;

use Voxyfy\AnadoluPay\Contracts\PaymentGatewayInterface;
use Voxyfy\AnadoluPay\Exceptions\DriverNotFoundException;

/**
 * AnadoluPay Ana Sınıfı
 *
 * AnadoluPay ödeme geçidi soyutlamasının ana giriş noktası.
 * Bu sınıf, ödeme geçidi driver'larını yönetir ve farklı Türk ödeme
 * sağlayıcılarıyla etkileşim için birleşik bir arayüz sunar.
 *
 * @example
 * // Varsayılan driver'ı al
 * $gateway = AnadoluPay::driver();
 *
 * // Belirli bir driver'ı al
 * $gateway = AnadoluPay::driver('iyzico');
 */
class AnadoluPay
{
    /**
     * Paket yapılandırma dizisi.
     *
     * @var array<string, mixed>
     */
    protected array $config;

    /**
     * Çözümlenmiş driver instance'larının dizisi.
     *
     * Aynı driver'ın birden fazla instance'ının oluşturulmasını önlemek için
     * oluşturulan gateway driver'larını saklar.
     *
     * @var array<string, PaymentGatewayInterface>
     */
    protected array $drivers = [];

    /**
     * Yeni bir AnadoluPay instance'ı oluşturur.
     *
     * Verilen yapılandırma ile AnadoluPay yöneticisini başlatır.
     * Yapılandırma, 'default' driver adını ve bireysel driver
     * yapılandırmalarını içeren 'drivers' dizisini içermelidir.
     *
     * @param  array<string, mixed>  $config  Paket yapılandırma dizisi:
     *                                        - 'default': string|null Varsayılan driver adı
     *                                        - 'drivers': array Driver'a özel yapılandırmalar
     */
    public function __construct(array $config)
    {
        $this->config = $config;
    }

    /**
     * Bir ödeme geçidi driver instance'ı döndürür.
     *
     * Yapılandırılmış bir ödeme geçidi driver instance'ı döndürür.
     * Driver adı belirtilmezse, yapılandırmadaki varsayılan driver kullanılır.
     * Driver instance'ları aynı istek içinde tekrar kullanım için önbelleğe alınır.
     *
     * @param  string|null  $name  Alınacak driver'ın adı. null ise yapılandırmadaki
     *                             varsayılan driver kullanılır. 'drivers' config
     *                             dizisindeki bir anahtarla eşleşmelidir.
     * @return PaymentGatewayInterface Çözümlenmiş ödeme geçidi instance'ı
     *
     * @throws DriverNotFoundException İstenen driver yapılandırılmamışsa veya
     *                                 driver sınıfı mevcut değilse fırlatılır
     *
     * @example
     * // Varsayılan driver'ı kullan
     * $gateway = $anadoluPay->driver();
     *
     * // Belirli bir driver kullan
     * $gateway = $anadoluPay->driver('iyzico');
     */
    public function driver(?string $name = null): PaymentGatewayInterface
    {
        $name = $name ?? $this->getDefaultDriver();

        if ($name === null) {
            throw new DriverNotFoundException(
                'Varsayılan ödeme driver\'ı yapılandırılmamış. Lütfen .env dosyanızda ANADOLUPAY_DRIVER değerini ayarlayın veya bir driver adı belirtin.'
            );
        }

        return $this->drivers[$name] ?? $this->drivers[$name] = $this->resolve($name);
    }

    /**
     * Yapılandırmadan varsayılan driver adını döndürür.
     *
     * Paket yapılandırmasında belirtilen varsayılan ödeme geçidi
     * driver adını alır.
     *
     * @return string|null Varsayılan driver adı, yapılandırılmamışsa null
     */
    public function getDefaultDriver(): ?string
    {
        return $this->config['default'] ?? null;
    }

    /**
     * Belirli bir driver için yapılandırmayı döndürür.
     *
     * Paket yapılandırmasının 'drivers' bölümünden adlandırılmış
     * driver'ın yapılandırma dizisini alır.
     *
     * @param  string  $name  Yapılandırması alınacak driver adı
     * @return array<string, mixed>|null Driver yapılandırma dizisi,
     *                                   bulunamazsa null
     */
    public function getDriverConfig(string $name): ?array
    {
        return $this->config['drivers'][$name] ?? null;
    }

    /**
     * Bir ödeme geçidi driver instance'ını çözümler.
     *
     * Belirtilen ödeme geçidi driver'ının yeni bir instance'ını oluşturur
     * ve döndürür. Driver sınıfı, driver yapılandırmasından belirlenir.
     *
     * @param  string  $name  Çözümlenecek driver'ın adı
     * @return PaymentGatewayInterface Çözümlenmiş ödeme geçidi instance'ı
     *
     * @throws DriverNotFoundException Driver yapılandırması eksikse veya
     *                                 driver sınıfı belirtilmemişse fırlatılır
     */
    protected function resolve(string $name): PaymentGatewayInterface
    {
        $config = $this->getDriverConfig($name);

        if ($config === null) {
            throw new DriverNotFoundException(
                "Ödeme driver'ı [{$name}] yapılandırılmamış. Lütfen anadolupay.php config dosyanıza ekleyin."
            );
        }

        $driverClass = $config['driver'] ?? null;

        if ($driverClass === null) {
            throw new DriverNotFoundException(
                "Ödeme driver'ı [{$name}] yapılandırmasında 'driver' sınıfı belirtilmemiş."
            );
        }

        if (! class_exists($driverClass)) {
            throw new DriverNotFoundException(
                "Ödeme driver sınıfı [{$driverClass}] mevcut değil."
            );
        }

        return new $driverClass($config);
    }

    /**
     * Tüm kayıtlı driver adlarını döndürür.
     *
     * Paket yapılandırmasında yapılandırılmış tüm driver adlarının
     * bir dizisini döndürür.
     *
     * @return array<int, string> Yapılandırılmış driver adları dizisi
     */
    public function getAvailableDrivers(): array
    {
        return array_keys($this->config['drivers'] ?? []);
    }

    /**
     * Bir driver'ın yapılandırılıp yapılandırılmadığını kontrol eder.
     *
     * Belirli bir driver'ın paket yapılandırmasında tanımlanıp
     * tanımlanmadığını belirler.
     *
     * @param  string  $name  Kontrol edilecek driver adı
     * @return bool Driver yapılandırılmışsa true, değilse false
     */
    public function hasDriver(string $name): bool
    {
        return isset($this->config['drivers'][$name]);
    }
}
