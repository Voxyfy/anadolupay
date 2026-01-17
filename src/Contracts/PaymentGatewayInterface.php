<?php

declare(strict_types=1);

namespace Voxyfy\AnadoluPay\Contracts;

use Voxyfy\AnadoluPay\Exceptions\PaymentFailedException;
use Voxyfy\AnadoluPay\Exceptions\UnsupportedOperationException;

/**
 * Payment Gateway Interface
 *
 * Defines the contract that all payment gateway drivers must implement.
 * This interface provides a unified API for interacting with different
 * Turkish payment providers, ensuring consistency across implementations.
 *
 * All gateway implementations should handle provider-specific logic internally
 * while exposing this standardized interface to the application.
 *
 * @package Voxyfy\AnadoluPay\Contracts
 */
interface PaymentGatewayInterface
{
    /**
     * Create a new payment transaction.
     *
     * Initiates a payment request with the configured payment provider.
     * The data array structure may vary depending on the payment type
     * (credit card, bank transfer, etc.) and the specific provider's requirements.
     *
     * Common data fields include:
     * - 'amount': float|int The payment amount (required)
     * - 'currency': string The ISO 4217 currency code (e.g., 'TRY', 'USD')
     * - 'order_id': string|int Unique order/transaction identifier
     * - 'description': string Payment description
     * - 'customer': array Customer information (name, email, phone, etc.)
     * - 'card': array Credit card details (if applicable)
     * - 'billing_address': array Billing address information
     * - 'shipping_address': array Shipping address information
     * - 'callback_url': string URL for payment notifications
     * - 'return_url': string URL to redirect after payment
     *
     * @param array<string, mixed> $data Payment data containing transaction details.
     *                                    Required and optional fields depend on
     *                                    the gateway implementation.
     *
     * @return array<string, mixed> Payment response containing:
     *                               - 'success': bool Whether payment was initiated
     *                               - 'transaction_id': string Provider's transaction ID
     *                               - 'status': string Payment status
     *                               - 'redirect_url': string|null URL for 3D Secure redirect
     *                               - 'raw_response': array Original provider response
     *
     * @throws PaymentFailedException When the payment cannot be processed
     *                                 due to validation errors, provider errors,
     *                                 or connectivity issues
     *
     * @example
     * $result = $gateway->createPayment([
     *     'amount' => 100.00,
     *     'currency' => 'TRY',
     *     'order_id' => 'ORDER-123',
     *     'customer' => [
     *         'name' => 'John Doe',
     *         'email' => 'john@example.com',
     *     ],
     * ]);
     */
    public function createPayment(array $data): array;

    /**
     * Verify a payment transaction.
     *
     * Confirms the status and authenticity of a payment transaction
     * with the payment provider. This method should be called after
     * receiving a callback or when checking payment status.
     *
     * @param string $transactionId The unique transaction identifier returned
     *                               by the payment provider during createPayment()
     *                               or received in callback notifications.
     * @param array<string, mixed> $data Additional verification data that may be
     *                                    required by specific providers, such as:
     *                                    - 'hash': string Verification hash from callback
     *                                    - 'order_id': string Original order ID
     *                                    - 'amount': float Expected amount for validation
     *
     * @return array<string, mixed> Verification response containing:
     *                               - 'verified': bool Whether payment is verified
     *                               - 'status': string Current payment status
     *                                           (completed, pending, failed, etc.)
     *                               - 'transaction_id': string Provider's transaction ID
     *                               - 'amount': float Verified payment amount
     *                               - 'currency': string Payment currency
     *                               - 'paid_at': string|null Payment completion timestamp
     *                               - 'raw_response': array Original provider response
     *
     * @throws PaymentFailedException When verification fails due to
     *                                 invalid transaction, provider errors,
     *                                 or connectivity issues
     *
     * @example
     * $result = $gateway->verify('TXN-123456', [
     *     'hash' => 'callback_verification_hash',
     * ]);
     *
     * if ($result['verified'] && $result['status'] === 'completed') {
     *     // Payment confirmed, fulfill the order
     * }
     */
    public function verify(string $transactionId, array $data = []): array;

    /**
     * Process a refund for a payment transaction.
     *
     * Initiates a full or partial refund for a previously completed payment.
     * Not all payment providers support partial refunds; check provider
     * documentation for specific capabilities.
     *
     * @param string $transactionId The unique transaction identifier of the
     *                               original payment to be refunded.
     * @param array<string, mixed> $data Refund data containing:
     *                                    - 'amount': float|null Refund amount. If null or
     *                                                not provided, full refund is processed.
     *                                    - 'reason': string|null Reason for the refund
     *                                    - 'reference': string|null Your internal refund reference
     *
     * @return array<string, mixed> Refund response containing:
     *                               - 'success': bool Whether refund was processed
     *                               - 'refund_id': string Provider's refund ID
     *                               - 'transaction_id': string Original transaction ID
     *                               - 'amount': float Refunded amount
     *                               - 'status': string Refund status
     *                               - 'raw_response': array Original provider response
     *
     * @throws PaymentFailedException When refund cannot be processed due to
     *                                 invalid transaction, insufficient funds,
     *                                 provider errors, or connectivity issues
     * @throws UnsupportedOperationException When the payment provider or specific
     *                                        payment method does not support refunds
     *
     * @example
     * // Full refund
     * $result = $gateway->refund('TXN-123456');
     *
     * // Partial refund
     * $result = $gateway->refund('TXN-123456', [
     *     'amount' => 50.00,
     *     'reason' => 'Customer requested partial refund',
     * ]);
     */
    public function refund(string $transactionId, array $data = []): array;
}
