<?php

/**
 * AnadoluPay Configuration File
 *
 * This configuration file defines the settings for the AnadoluPay payment gateway
 * abstraction package. It allows you to configure multiple payment providers
 * and set a default driver for handling payments.
 *
 * @package Voxyfy\AnadoluPay
 */

return [

    /*
    |--------------------------------------------------------------------------
    | Default Payment Driver
    |--------------------------------------------------------------------------
    |
    | This option defines the default payment gateway driver that will be used
    | when no specific driver is requested. Set this value to the name of one
    | of the drivers configured in the 'drivers' array below.
    |
    | When set to null, you must explicitly specify a driver when making
    | payment requests.
    |
    | Supported: Any key from the 'drivers' array below
    |
    */

    'default' => env('ANADOLUPAY_DRIVER', null),

    /*
    |--------------------------------------------------------------------------
    | Payment Gateway Drivers
    |--------------------------------------------------------------------------
    |
    | Here you may configure the payment gateway drivers for your application.
    | Each driver represents a specific payment provider integration.
    |
    | Each driver configuration should include:
    | - 'driver': The gateway class that implements PaymentGatewayInterface
    | - Provider-specific credentials and settings
    |
    | Example configuration for a hypothetical provider:
    |
    | 'provider_name' => [
    |     'driver' => \Voxyfy\AnadoluPay\Gateways\ProviderGateway::class,
    |     'merchant_id' => env('PROVIDER_MERCHANT_ID'),
    |     'api_key' => env('PROVIDER_API_KEY'),
    |     'api_secret' => env('PROVIDER_API_SECRET'),
    |     'sandbox' => env('PROVIDER_SANDBOX', true),
    | ],
    |
    */

    'drivers' => [

        // Payment gateway drivers will be configured here.
        // Each driver should implement PaymentGatewayInterface.

    ],

];
