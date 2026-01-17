<?php

declare(strict_types=1);

namespace Voxyfy\AnadoluPay\Facades;

use Illuminate\Support\Facades\Facade;
use Voxyfy\AnadoluPay\Contracts\PaymentGatewayInterface;

/**
 * AnadoluPay Facade
 *
 * Provides a static interface to the AnadoluPay payment gateway manager.
 * This facade allows convenient access to payment gateway functionality
 * throughout your Laravel application without needing to inject the
 * AnadoluPay instance.
 *
 * @package Voxyfy\AnadoluPay\Facades
 *
 * @method static PaymentGatewayInterface driver(string|null $name = null) Get a payment gateway driver instance
 * @method static string|null getDefaultDriver() Get the default driver name
 * @method static array|null getDriverConfig(string $name) Get configuration for a specific driver
 * @method static array getAvailableDrivers() Get all registered driver names
 * @method static bool hasDriver(string $name) Check if a driver is configured
 *
 * @see \Voxyfy\AnadoluPay\AnadoluPay
 *
 * @example
 * // Get the default payment gateway driver
 * $gateway = AnadoluPay::driver();
 *
 * // Get a specific payment gateway driver
 * $gateway = AnadoluPay::driver('iyzico');
 *
 * // Create a payment using the default driver
 * $result = AnadoluPay::driver()->createPayment($paymentData);
 *
 * // Check available drivers
 * $drivers = AnadoluPay::getAvailableDrivers();
 */
class AnadoluPay extends Facade
{
    /**
     * Get the registered name of the component.
     *
     * Returns the service container binding key that this facade
     * proxies to. The AnadoluPay class is registered as a singleton
     * in the service provider.
     *
     * @return string The service container binding key
     */
    protected static function getFacadeAccessor(): string
    {
        return \Voxyfy\AnadoluPay\AnadoluPay::class;
    }
}
