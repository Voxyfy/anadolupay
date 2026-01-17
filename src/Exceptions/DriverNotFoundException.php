<?php

declare(strict_types=1);

namespace Voxyfy\AnadoluPay\Exceptions;

use Exception;

/**
 * Driver Not Found Exception
 *
 * Thrown when attempting to use a payment gateway driver that has not been
 * configured or does not exist. This exception indicates a configuration
 * issue that should be resolved before the application can process payments.
 *
 * Common causes:
 * - The requested driver name is misspelled
 * - The driver has not been added to the 'drivers' array in anadolupay.php
 * - The driver class specified in configuration does not exist
 * - No default driver is configured when calling driver() without arguments
 *
 * @package Voxyfy\AnadoluPay\Exceptions
 *
 * @example
 * // This exception would be thrown if 'iyzico' is not configured:
 * try {
 *     $gateway = AnadoluPay::driver('iyzico');
 * } catch (DriverNotFoundException $e) {
 *     // Handle missing driver configuration
 *     Log::error('Payment driver not configured: ' . $e->getMessage());
 * }
 */
class DriverNotFoundException extends Exception
{
    /**
     * Create a new Driver Not Found exception instance.
     *
     * @param string $message The exception message describing which driver
     *                        was not found and potential solutions
     * @param int $code The exception code (default: 0)
     * @param \Throwable|null $previous The previous throwable for exception chaining
     *
     * @return void
     */
    public function __construct(
        string $message = 'The requested payment driver was not found.',
        int $code = 0,
        ?\Throwable $previous = null
    ) {
        parent::__construct($message, $code, $previous);
    }
}
