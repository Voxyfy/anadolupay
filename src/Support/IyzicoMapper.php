<?php

declare(strict_types=1);

namespace Voxyfy\AnadoluPay\Support;

use Voxyfy\AnadoluPay\DTO\CreatePaymentData;
use Voxyfy\AnadoluPay\Exceptions\PaymentFailedException;

/**
 * AnadoluPay DTO'larını Iyzico payload formatına dönüştürür.
 */
class IyzicoMapper
{
    /**
     * 3DS initialize payload'unu oluşturur.
     *
     * @return array<string, mixed>
     */
    public function to3dsInitializePayload(CreatePaymentData $data, string $callbackUrl): array
    {
        $customer = $data->customer;
        $card = $customer['card'] ?? null;

        if (! is_array($card)) {
            throw new PaymentFailedException('3DS başlatma için kart bilgileri gereklidir.');
        }

        $fullName = trim((string) ($customer['name'] ?? ''));
        $explicitSurname = trim((string) ($customer['surname'] ?? ''));
        $nameParts = array_values(array_filter(explode(' ', $fullName)));
        $firstName = $nameParts[0] ?? 'Customer';
        $lastName = $explicitSurname !== '' ? $explicitSurname : ($nameParts[1] ?? 'Unknown');

        return [
            'locale' => (string) ($customer['locale'] ?? 'tr'),
            'price' => (string) $data->amount,
            'paidPrice' => (string) $data->amount,
            'currency' => $data->currency,
            'installment' => (int) ($customer['installment'] ?? 1),
            'conversationId' => $data->orderId,
            'basketId' => $data->orderId,
            'paymentChannel' => (string) ($customer['paymentChannel'] ?? 'WEB'),
            'paymentGroup' => (string) ($customer['paymentGroup'] ?? 'PRODUCT'),
            'callbackUrl' => $callbackUrl,
            'paymentCard' => [
                'cardHolderName' => (string) ($card['cardHolderName'] ?? $fullName ?: 'Customer'),
                'cardNumber' => (string) ($card['cardNumber'] ?? ''),
                'expireYear' => (string) ($card['expireYear'] ?? ''),
                'expireMonth' => (string) ($card['expireMonth'] ?? ''),
                'cvc' => (string) ($card['cvc'] ?? ''),
            ],
            'buyer' => [
                'id' => (string) ($customer['id'] ?? $data->orderId),
                'name' => $firstName,
                'surname' => $lastName,
                'email' => (string) ($customer['email'] ?? 'unknown@example.com'),
                'gsmNumber' => (string) ($customer['gsmNumber'] ?? $customer['phone'] ?? '+900000000000'),
                'identityNumber' => (string) ($customer['identityNumber'] ?? '11111111111'),
                'registrationAddress' => (string) ($customer['address'] ?? 'Unknown'),
                'ip' => (string) ($customer['ip'] ?? '127.0.0.1'),
                'city' => (string) ($customer['city'] ?? 'Istanbul'),
                'country' => (string) ($customer['country'] ?? 'Turkey'),
                'zipCode' => (string) ($customer['zipCode'] ?? '00000'),
            ],
            'shippingAddress' => [
                'contactName' => $fullName ?: 'Customer',
                'city' => (string) ($customer['shipping_city'] ?? $customer['city'] ?? 'Istanbul'),
                'country' => (string) ($customer['shipping_country'] ?? $customer['country'] ?? 'Turkey'),
                'address' => (string) ($customer['shipping_address'] ?? $customer['address'] ?? 'Unknown'),
                'zipCode' => (string) ($customer['shipping_zip'] ?? $customer['zipCode'] ?? '00000'),
            ],
            'billingAddress' => [
                'contactName' => $fullName ?: 'Customer',
                'city' => (string) ($customer['billing_city'] ?? $customer['city'] ?? 'Istanbul'),
                'country' => (string) ($customer['billing_country'] ?? $customer['country'] ?? 'Turkey'),
                'address' => (string) ($customer['billing_address'] ?? $customer['address'] ?? 'Unknown'),
                'zipCode' => (string) ($customer['billing_zip'] ?? $customer['zipCode'] ?? '00000'),
            ],
            'basketItems' => [
                [
                    'id' => $data->orderId,
                    'name' => 'Order '.$data->orderId,
                    'category1' => 'Payment',
                    'itemType' => 'VIRTUAL',
                    'price' => (string) $data->amount,
                ],
            ],
        ];
    }

    /**
     * 3DS callback payload'unu normalize eder.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function normalizeCallbackStatus(array $payload): array
    {
        $status = strtolower((string) ($payload['status'] ?? ''));
        $mdStatus = (string) ($payload['mdStatus'] ?? '');
        $isSuccess = $status === 'success' && $mdStatus === '1';

        if ($status === '') {
            $isSuccess = false;
        }

        return [
            'success' => $isSuccess,
            'status' => $isSuccess ? 'success' : 'failed',
            'paymentId' => $payload['paymentId'] ?? null,
            'conversationId' => $payload['conversationId'] ?? null,
            'conversationData' => $payload['conversationData'] ?? null,
            'mdStatus' => $payload['mdStatus'] ?? null,
        ];
    }

    /**
     * 3DS auth payload'unu oluşturur.
     *
     * @return array<string, mixed>
     */
    public function to3dsAuthPayload(string $paymentId, ?string $conversationData = null): array
    {
        $payload = [
            'paymentId' => $paymentId,
        ];

        if ($conversationData !== null) {
            $payload['conversationData'] = $conversationData;
        }

        return $payload;
    }
}
