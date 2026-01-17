<?php

declare(strict_types=1);

namespace Voxyfy\AnadoluPay\Exceptions;

use Exception;

/**
 * Unsupported Operation Exception
 *
 * Thrown when attempting to perform an operation that is not supported
 * by a specific payment gateway driver. Not all payment providers support
 * all operations (e.g., some may not support refunds or partial refunds).
 *
 * This exception helps differentiate between operational failures and
 * feature limitations of specific payment providers.
 *
 * Common scenarios:
 * - Attempting to refund a payment on a provider that doesn't support refunds
 * - Requesting partial refund when only full refunds are supported
 * - Using a payment method not supported by the gateway
 * - Attempting recurring payments on a non-supporting provider
 *
 * @package Voxyfy\AnadoluPay\Exceptions
 *
 * @example
 * try {
 *     $gateway->refund($transactionId, ['amount' => 50.00]);
 * } catch (UnsupportedOperationException $e) {
 *     // This provider may not support partial refunds
 *     // Consider offering full refund or alternative solution
 *     Log::warning('Partial refund not supported: ' . $e->getMessage());
 * }
 */
class UnsupportedOperationException extends Exception
{
    /**
     * The name of the payment gateway driver.
     *
     * @var string|null
     */
    protected ?string $driverName = null;

    /**
     * The name of the unsupported operation.
     *
     * @var string|null
     */
    protected ?string $operationName = null;

    /**
     * Create a new Unsupported Operation exception instance.
     *
     * @param string $message The exception message describing the unsupported
     *                        operation and the gateway that doesn't support it
     * @param int $code The exception code (default: 0)
     * @param \Throwable|null $previous The previous throwable for exception chaining
     *
     * @return void
     */
    public function __construct(
        string $message = 'This operation is not supported by the payment gateway.',
        int $code = 0,
        ?\Throwable $previous = null
    ) {
        parent::__construct($message, $code, $previous);
    }

    /**
     * Set the driver name that doesn't support the operation.
     *
     * @param string $driverName The name of the payment gateway driver
     *
     * @return static Returns the exception instance for method chaining
     */
    public function setDriverName(string $driverName): static
    {
        $this->driverName = $driverName;

        return $this;
    }

    /**
     * Get the driver name that doesn't support the operation.
     *
     * @return string|null The driver name, or null if not set
     */
    public function getDriverName(): ?string
    {
        return $this->driverName;
    }

    /**
     * Set the name of the unsupported operation.
     *
     * @param string $operationName The name of the operation that failed
     *                              (e.g., 'refund', 'partial_refund', 'recurring')
     *
     * @return static Returns the exception instance for method chaining
     */
    public function setOperationName(string $operationName): static
    {
        $this->operationName = $operationName;

        return $this;
    }

    /**
     * Get the name of the unsupported operation.
     *
     * @return string|null The operation name, or null if not set
     */
    public function getOperationName(): ?string
    {
        return $this->operationName;
    }
}
