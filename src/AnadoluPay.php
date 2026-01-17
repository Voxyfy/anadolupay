<?php

declare(strict_types=1);

namespace Voxyfy\AnadoluPay;

use Voxyfy\AnadoluPay\Contracts\PaymentGatewayInterface;
use Voxyfy\AnadoluPay\Exceptions\DriverNotFoundException;

/**
 * AnadoluPay Core Class
 *
 * The main entry point for the AnadoluPay payment gateway abstraction.
 * This class manages payment gateway drivers and provides a unified interface
 * for interacting with different Turkish payment providers.
 *
 * @package Voxyfy\AnadoluPay
 *
 * @example
 * // Get the default driver
 * $gateway = AnadoluPay::driver();
 *
 * // Get a specific driver
 * $gateway = AnadoluPay::driver('iyzico');
 */
class AnadoluPay
{
    /**
     * The configuration array for the package.
     *
     * @var array<string, mixed>
     */
    protected array $config;

    /**
     * The array of resolved driver instances.
     *
     * Stores instantiated gateway drivers to avoid creating multiple
     * instances of the same driver during a request lifecycle.
     *
     * @var array<string, PaymentGatewayInterface>
     */
    protected array $drivers = [];

    /**
     * Create a new AnadoluPay instance.
     *
     * Initializes the AnadoluPay manager with the provided configuration.
     * The configuration should include the 'default' driver name and
     * a 'drivers' array with individual driver configurations.
     *
     * @param array<string, mixed> $config The package configuration array containing:
     *                                      - 'default': string|null The default driver name
     *                                      - 'drivers': array Driver-specific configurations
     *
     * @return void
     */
    public function __construct(array $config)
    {
        $this->config = $config;
    }

    /**
     * Get a payment gateway driver instance.
     *
     * Returns a configured payment gateway driver instance. If no driver name
     * is provided, the default driver from configuration will be used.
     * Driver instances are cached for reuse within the same request.
     *
     * @param string|null $name The name of the driver to retrieve. If null,
     *                          uses the default driver from configuration.
     *                          Must match a key in the 'drivers' config array.
     *
     * @return PaymentGatewayInterface The resolved payment gateway instance
     *
     * @throws DriverNotFoundException When the requested driver is not configured
     *                                  or the driver class does not exist
     *
     * @example
     * // Using the default driver
     * $gateway = $anadoluPay->driver();
     *
     * // Using a specific driver
     * $gateway = $anadoluPay->driver('iyzico');
     */
    public function driver(?string $name = null): PaymentGatewayInterface
    {
        $name = $name ?? $this->getDefaultDriver();

        if ($name === null) {
            throw new DriverNotFoundException(
                'No default payment driver configured. Please set ANADOLUPAY_DRIVER in your .env file or specify a driver name.'
            );
        }

        return $this->drivers[$name] ?? $this->drivers[$name] = $this->resolve($name);
    }

    /**
     * Get the default driver name from configuration.
     *
     * Retrieves the default payment gateway driver name as specified
     * in the package configuration.
     *
     * @return string|null The default driver name, or null if not configured
     */
    public function getDefaultDriver(): ?string
    {
        return $this->config['default'] ?? null;
    }

    /**
     * Get the configuration for a specific driver.
     *
     * Retrieves the configuration array for a named driver from
     * the 'drivers' section of the package configuration.
     *
     * @param string $name The driver name to get configuration for
     *
     * @return array<string, mixed>|null The driver configuration array,
     *                                    or null if not found
     */
    public function getDriverConfig(string $name): ?array
    {
        return $this->config['drivers'][$name] ?? null;
    }

    /**
     * Resolve a payment gateway driver instance.
     *
     * Creates and returns a new instance of the specified payment gateway
     * driver. The driver class is determined from the driver configuration.
     *
     * @param string $name The name of the driver to resolve
     *
     * @return PaymentGatewayInterface The resolved payment gateway instance
     *
     * @throws DriverNotFoundException When the driver configuration is missing
     *                                  or the driver class is not specified
     */
    protected function resolve(string $name): PaymentGatewayInterface
    {
        $config = $this->getDriverConfig($name);

        if ($config === null) {
            throw new DriverNotFoundException(
                "Payment driver [{$name}] is not configured. Please add it to your anadolupay.php config file."
            );
        }

        $driverClass = $config['driver'] ?? null;

        if ($driverClass === null) {
            throw new DriverNotFoundException(
                "Payment driver [{$name}] does not have a 'driver' class specified in its configuration."
            );
        }

        if (!class_exists($driverClass)) {
            throw new DriverNotFoundException(
                "Payment driver class [{$driverClass}] does not exist."
            );
        }

        return new $driverClass($config);
    }

    /**
     * Get all registered driver names.
     *
     * Returns an array of all driver names that have been configured
     * in the package configuration.
     *
     * @return array<int, string> Array of configured driver names
     */
    public function getAvailableDrivers(): array
    {
        return array_keys($this->config['drivers'] ?? []);
    }

    /**
     * Check if a driver is configured.
     *
     * Determines whether a specific driver has been configured
     * in the package configuration.
     *
     * @param string $name The driver name to check
     *
     * @return bool True if the driver is configured, false otherwise
     */
    public function hasDriver(string $name): bool
    {
        return isset($this->config['drivers'][$name]);
    }
}
