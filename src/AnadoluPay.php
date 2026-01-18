<?php

declare(strict_types=1);

namespace Voxyfy\AnadoluPay;

use Voxyfy\AnadoluPay\Contracts\PaymentGatewayInterface;
use Voxyfy\AnadoluPay\Exceptions\DriverNotFoundException;
use Voxyfy\AnadoluPay\Exceptions\PaymentFailedException;

/**
 * AnadoluPay Ana Sınıfı
 *
 * Ödeme geçidi driver'larını yönetir ve farklı Türk ödeme
 * sağlayıcılarıyla etkileşim için birleşik bir arayüz sunar.
 */
class AnadoluPay
{
    /**
     * Çözümlenmiş driver instance'larının önbelleği.
     *
     * @var array<string, PaymentGatewayInterface>
     */
    protected array $resolved = [];

    /**
     * Belirtilen ödeme geçidi driver'ını döndürür.
     *
     * @param  string  $name  Driver adı (config'deki 'drivers' anahtarı)
     * @return PaymentGatewayInterface Çözümlenmiş gateway instance'ı
     *
     * @throws DriverNotFoundException Driver yapılandırılmamışsa
     * @throws PaymentFailedException Sınıf interface'i implement etmiyorsa
     */
    public function driver(string $name): PaymentGatewayInterface
    {
        if (isset($this->resolved[$name])) {
            return $this->resolved[$name];
        }

        $drivers = config('anadolupay.drivers', []);

        if (! isset($drivers[$name])) {
            throw new DriverNotFoundException($name, array_keys($drivers));
        }

        $class = $drivers[$name];
        $instance = app()->make($class);

        if (! $instance instanceof PaymentGatewayInterface) {
            throw new PaymentFailedException(
                message: "Driver '{$name}' must implement PaymentGatewayInterface.",
                context: [
                    'driver' => $name,
                    'class' => $class,
                ],
            );
        }

        return $this->resolved[$name] = $instance;
    }
}
