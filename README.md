# AnadoluPay

[![Latest Version on Packagist](https://img.shields.io/packagist/v/voxyfy/anadolupay.svg?style=flat-square)](https://packagist.org/packages/voxyfy/anadolupay)
[![GitHub Tests Action Status](https://img.shields.io/github/actions/workflow/status/voxyfy/anadolupay/run-tests.yml?branch=main&label=tests&style=flat-square)](https://github.com/voxyfy/anadolupay/actions?query=workflow%3Arun-tests+branch%3Amain)
[![Total Downloads](https://img.shields.io/packagist/dt/voxyfy/anadolupay.svg?style=flat-square)](https://packagist.org/packages/voxyfy/anadolupay)

A unified Laravel 12 payment gateway abstraction for Turkish payment providers. AnadoluPay provides a clean, consistent API for integrating multiple Turkish payment gateways into your Laravel application.

## Requirements

- PHP 8.2 or higher
- Laravel 12.x

## Installation

Install the package via Composer:

```bash
composer require voxyfy/anadolupay
```

Publish the configuration file:

```bash
php artisan vendor:publish --tag="anadolupay-config"
```

## Configuration

After publishing, the configuration file will be located at `config/anadolupay.php`:

```php
return [
    // Default payment driver
    'default' => env('ANADOLUPAY_DRIVER', null),

    // Payment gateway drivers
    'drivers' => [
        // Configure your payment providers here
    ],
];
```

Set your default driver in your `.env` file:

```env
ANADOLUPAY_DRIVER=your_driver_name
```

## Basic Usage

### Using the Facade

```php
use Voxyfy\AnadoluPay\Facades\AnadoluPay;

// Get the default driver
$gateway = AnadoluPay::driver();

// Get a specific driver
$gateway = AnadoluPay::driver('iyzico');

// Create a payment
$result = AnadoluPay::driver()->createPayment([
    'amount' => 100.00,
    'currency' => 'TRY',
    'order_id' => 'ORDER-123',
    'customer' => [
        'name' => 'John Doe',
        'email' => 'john@example.com',
    ],
]);

// Verify a payment
$verification = AnadoluPay::driver()->verify($transactionId);

// Process a refund
$refund = AnadoluPay::driver()->refund($transactionId, [
    'amount' => 50.00, // Partial refund
    'reason' => 'Customer request',
]);
```

### Using Dependency Injection

```php
use Voxyfy\AnadoluPay\AnadoluPay;

class PaymentController extends Controller
{
    public function __construct(
        protected AnadoluPay $anadoluPay
    ) {}

    public function process()
    {
        $gateway = $this->anadoluPay->driver();
        // ...
    }
}
```

### Checking Available Drivers

```php
// Get all configured driver names
$drivers = AnadoluPay::getAvailableDrivers();

// Check if a specific driver is configured
if (AnadoluPay::hasDriver('iyzico')) {
    // Driver is available
}
```

## Creating a Gateway Driver

To create a custom payment gateway driver, implement the `PaymentGatewayInterface`:

```php
<?php

namespace App\PaymentGateways;

use Voxyfy\AnadoluPay\Contracts\PaymentGatewayInterface;

class CustomGateway implements PaymentGatewayInterface
{
    public function __construct(protected array $config)
    {
        // Initialize with configuration
    }

    public function createPayment(array $data): array
    {
        // Implement payment creation logic
    }

    public function verify(string $transactionId, array $data = []): array
    {
        // Implement payment verification logic
    }

    public function refund(string $transactionId, array $data = []): array
    {
        // Implement refund logic
    }
}
```

Then register it in your configuration:

```php
'drivers' => [
    'custom' => [
        'driver' => \App\PaymentGateways\CustomGateway::class,
        'api_key' => env('CUSTOM_GATEWAY_API_KEY'),
        'api_secret' => env('CUSTOM_GATEWAY_API_SECRET'),
        'sandbox' => env('CUSTOM_GATEWAY_SANDBOX', true),
    ],
],
```

## Supported Providers (Planned)

The following Turkish payment providers are planned for future releases:

- iyzico
- PayTR
- Param
- Sipay
- Craftgate

## Exception Handling

AnadoluPay provides specific exceptions for different error scenarios:

```php
use Voxyfy\AnadoluPay\Exceptions\DriverNotFoundException;
use Voxyfy\AnadoluPay\Exceptions\PaymentFailedException;
use Voxyfy\AnadoluPay\Exceptions\UnsupportedOperationException;

try {
    $result = AnadoluPay::driver()->createPayment($data);
} catch (DriverNotFoundException $e) {
    // Driver not configured
} catch (PaymentFailedException $e) {
    // Payment processing failed
    $errorCode = $e->getErrorCode();
    $userMessage = $e->getUserMessage();
} catch (UnsupportedOperationException $e) {
    // Operation not supported by this gateway
}
```

## Testing

```bash
composer test
```

## Changelog

Please see [CHANGELOG](CHANGELOG.md) for more information on what has changed recently.

## Contributing

Contributions are welcome! Please see [CONTRIBUTING](CONTRIBUTING.md) for details.

## Security Vulnerabilities

If you discover a security vulnerability, please send an e-mail to security@voxyfy.com.

## Credits

- [Voxyfy](https://github.com/Voxyfy)
- [All Contributors](../../contributors)

## License

The MIT License (MIT). Please see [License File](LICENSE.md) for more information.
