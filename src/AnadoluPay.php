<?php

declare(strict_types=1);

namespace Voxyfy\AnadoluPay;

use Voxyfy\AnadoluPay\Contracts\PaymentGatewayInterface;
use Voxyfy\AnadoluPay\Exceptions\DriverNotFoundException;
use Voxyfy\AnadoluPay\Exceptions\PaymentFailedException;
use Voxyfy\AnadoluPay\Gateways\Bank\AbstractBankGateway;
use Voxyfy\AnadoluPay\Support\OrderNumber;

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
        $banks = config('anadolupay.banks', []);

        if (isset($drivers[$name])) {
            $class = $drivers[$name];
            $instance = app()->make($class);
        } elseif (isset($banks[$name])) {
            $class = $banks[$name]['gateway'] ?? null;
            $instance = $this->makeBankGateway($name, $banks[$name]);
        } else {
            throw new DriverNotFoundException(
                $name,
                array_merge(array_keys($drivers), array_keys($banks)),
            );
        }

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

    /**
     * Yapılandırılmış tüm driver ve banka anahtarlarını döndürür.
     *
     * @return list<string>
     */
    public function available(): array
    {
        return array_values(array_unique(array_merge(
            array_keys(config('anadolupay.drivers', [])),
            array_keys(config('anadolupay.banks', [])),
        )));
    }

    /**
     * `anadolupay.order` ayarlarına göre yeni bir sipariş numarası üretir.
     *
     * Numara ödeme başlatılmadan önce gerekebilir: geri dönüş (callback)
     * URL'ine ve satıcının kendi sipariş kaydına aynı değerin yazılması
     * gerekir, dolayısıyla üretimin `CreatePaymentData` içinde kalması
     * her zaman yeterli olmaz.
     *
     * @throws PaymentFailedException Ön ek ya da uzunluk yapılandırması geçersizse
     */
    public function orderId(): string
    {
        return OrderNumber::generate();
    }

    /**
     * Banka preset'inden sanal POS driver'ı üretir.
     *
     * @param  array<string, mixed>  $bankConfig
     *
     * @throws PaymentFailedException Preset'te geçerli bir gateway sınıfı yoksa
     */
    protected function makeBankGateway(string $name, array $bankConfig): object
    {
        $class = $bankConfig['gateway'] ?? null;

        if (! is_string($class) || ! class_exists($class)) {
            throw new PaymentFailedException(
                message: "Bank preset '{$name}' must declare a valid 'gateway' class.",
                context: ['bank' => $name, 'gateway' => $class],
            );
        }

        if (! is_subclass_of($class, AbstractBankGateway::class)) {
            throw new PaymentFailedException(
                message: "Bank gateway '{$class}' must extend AbstractBankGateway.",
                context: ['bank' => $name, 'gateway' => $class],
            );
        }

        return $class::forBank($name, $bankConfig);
    }
}
