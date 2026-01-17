<?php

declare(strict_types=1);

namespace Voxyfy\AnadoluPay\Exceptions;

use Exception;

/**
 * Payment Failed Exception
 *
 * Thrown when a payment operation fails during processing. This exception
 * encapsulates errors that occur during payment creation, verification,
 * or refund operations, providing detailed information about the failure.
 *
 * The exception includes methods to access the original error response
 * from the payment provider, enabling detailed error handling and logging.
 *
 * Common failure scenarios:
 * - Insufficient funds on the card
 * - Invalid card details
 * - Declined by issuing bank
 * - Fraud detection triggered
 * - Network/connectivity errors with payment provider
 * - Invalid transaction data
 * - Expired or invalid transaction for verification/refund
 *
 *
 * @example
 * try {
 *     $result = $gateway->createPayment($paymentData);
 * } catch (PaymentFailedException $e) {
 *     $errorCode = $e->getErrorCode();
 *     $providerResponse = $e->getProviderResponse();
 *
 *     Log::error('Payment failed', [
 *         'message' => $e->getMessage(),
 *         'code' => $errorCode,
 *         'provider_response' => $providerResponse,
 *     ]);
 *
 *     // Show user-friendly error message based on error code
 *     return back()->withErrors(['payment' => $e->getUserMessage()]);
 * }
 */
class PaymentFailedException extends Exception
{
    /**
     * The error code returned by the payment provider.
     */
    protected ?string $errorCode = null;

    /**
     * The raw response from the payment provider.
     *
     * @var array<string, mixed>
     */
    protected array $providerResponse = [];

    /**
     * A user-friendly error message suitable for display.
     */
    protected ?string $userMessage = null;

    /**
     * Create a new Payment Failed exception instance.
     *
     * @param  string  $message  The technical exception message describing
     *                           the failure reason
     * @param  int  $code  The exception code (default: 0)
     * @param  \Throwable|null  $previous  The previous throwable for exception chaining
     * @return void
     */
    public function __construct(
        string $message = 'The payment could not be processed.',
        int $code = 0,
        ?\Throwable $previous = null
    ) {
        parent::__construct($message, $code, $previous);
    }

    /**
     * Set the error code from the payment provider.
     *
     * @param  string  $errorCode  The provider-specific error code
     * @return static Returns the exception instance for method chaining
     */
    public function setErrorCode(string $errorCode): static
    {
        $this->errorCode = $errorCode;

        return $this;
    }

    /**
     * Get the error code from the payment provider.
     *
     * @return string|null The provider-specific error code, or null if not set
     */
    public function getErrorCode(): ?string
    {
        return $this->errorCode;
    }

    /**
     * Set the raw response from the payment provider.
     *
     * Stores the complete response from the payment provider for
     * debugging and detailed error analysis.
     *
     * @param  array<string, mixed>  $response  The complete provider response array
     * @return static Returns the exception instance for method chaining
     */
    public function setProviderResponse(array $response): static
    {
        $this->providerResponse = $response;

        return $this;
    }

    /**
     * Get the raw response from the payment provider.
     *
     * @return array<string, mixed> The complete provider response array
     */
    public function getProviderResponse(): array
    {
        return $this->providerResponse;
    }

    /**
     * Set a user-friendly error message.
     *
     * This message should be safe to display to end users and should
     * not contain sensitive technical details or provider-specific codes.
     *
     * @param  string  $message  A user-friendly message suitable for display
     * @return static Returns the exception instance for method chaining
     */
    public function setUserMessage(string $message): static
    {
        $this->userMessage = $message;

        return $this;
    }

    /**
     * Get the user-friendly error message.
     *
     * Returns a message suitable for display to end users. If no user
     * message has been explicitly set, returns a generic message.
     *
     * @return string A user-friendly error message
     */
    public function getUserMessage(): string
    {
        return $this->userMessage ?? 'Payment could not be processed. Please try again or use a different payment method.';
    }

    /**
     * Create a new exception instance with provider details.
     *
     * Factory method for creating a fully populated exception in a single call.
     *
     * @param  string  $message  The technical exception message
     * @param  string|null  $errorCode  The provider-specific error code
     * @param  array<string, mixed>  $providerResponse  The raw provider response
     * @param  string|null  $userMessage  The user-friendly message
     * @param  \Throwable|null  $previous  The previous throwable
     * @return static A fully configured exception instance
     *
     * @example
     * throw PaymentFailedException::withDetails(
     *     'Card declined by issuing bank',
     *     'CARD_DECLINED',
     *     $apiResponse,
     *     'Your card was declined. Please try a different card.'
     * );
     */
    public static function withDetails(
        string $message,
        ?string $errorCode = null,
        array $providerResponse = [],
        ?string $userMessage = null,
        ?\Throwable $previous = null
    ): static {
        $exception = new static($message, 0, $previous);

        if ($errorCode !== null) {
            $exception->setErrorCode($errorCode);
        }

        if (! empty($providerResponse)) {
            $exception->setProviderResponse($providerResponse);
        }

        if ($userMessage !== null) {
            $exception->setUserMessage($userMessage);
        }

        return $exception;
    }
}
