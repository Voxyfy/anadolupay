<?php

declare(strict_types=1);

namespace Voxyfy\AnadoluPay\Gateways;

use Voxyfy\AnadoluPay\Contracts\PaymentGatewayInterface;
use Voxyfy\AnadoluPay\DTO\CreatePaymentData;
use Voxyfy\AnadoluPay\DTO\PaymentResponse;
use Voxyfy\AnadoluPay\DTO\RefundPaymentData;
use Voxyfy\AnadoluPay\DTO\RefundResponse;
use Voxyfy\AnadoluPay\DTO\VerificationResponse;
use Voxyfy\AnadoluPay\DTO\VerifyPaymentData;
use Voxyfy\AnadoluPay\Exceptions\PaymentFailedException;
use Voxyfy\AnadoluPay\Exceptions\UnsupportedOperationException;
use Voxyfy\AnadoluPay\Support\IyzicoHttpClient;
use Voxyfy\AnadoluPay\Support\IyzicoMapper;

/**
 * Iyzico 3DS gateway adaptoru.
 */
class IyzicoGateway implements PaymentGatewayInterface
{
    public function __construct(
        private readonly IyzicoHttpClient $client,
        private readonly IyzicoMapper $mapper,
    ) {}

    public static function fromConfig(): self
    {
        $config = config('anadolupay.iyzico', []);

        $client = new IyzicoHttpClient(
            (string) ($config['base_url'] ?? ''),
            (string) ($config['api_key'] ?? ''),
            (string) ($config['secret_key'] ?? ''),
        );

        return new self($client, new IyzicoMapper);
    }

    /**
     * 3DS ödemeyi başlatır ve ham HTML içeriği döndürür.
     */
    public function createPayment(CreatePaymentData $data): PaymentResponse
    {
        $callbackUrl = (string) config('anadolupay.iyzico.callback_url');
        $payload = $this->mapper->to3dsInitializePayload($data, $callbackUrl);
        $response = $this->client->post('/payment/3dsecure/initialize', $payload);

        if (strtolower((string) ($response['status'] ?? '')) !== 'success') {
            throw new PaymentFailedException(
                message: (string) ($response['errorMessage'] ?? 'Iyzico 3DS başlatma başarısız.'),
                context: [
                    'errorCode' => $response['errorCode'] ?? null,
                    'response' => $response,
                ],
            );
        }

        // threeDSHtmlContent base64 HTML içerir; tüketen uygulama çözsün.
        $threeDsHtmlContent = $response['threeDSHtmlContent'] ?? null;

        return new PaymentResponse(
            success: true,
            paymentId: null,
            redirectUrl: null,
            errorMessage: null,
            raw: array_merge($response, [
                'threeDSHtmlContent' => $threeDsHtmlContent,
            ]),
        );
    }

    /**
     * Redirect callback veya webhook bildirimini doğrular.
     */
    public function verify(VerifyPaymentData $data): VerificationResponse
    {
        $payload = $data->payload;

        if (isset($payload['paymentId'], $payload['mdStatus'])) {
            $normalized = $this->mapper->normalizeCallbackStatus($payload);

            if (! $normalized['success']) {
                return new VerificationResponse(
                    success: false,
                    paymentId: $normalized['paymentId'],
                    status: 'failed',
                    raw: $payload,
                );
            }

            $authPayload = $this->mapper->to3dsAuthPayload(
                (string) $normalized['paymentId'],
                $normalized['conversationData'] ? (string) $normalized['conversationData'] : null,
            );

            $authResponse = $this->client->post('/payment/3dsecure/auth', $authPayload);
            $authStatus = strtolower((string) ($authResponse['status'] ?? ''));

            if ($authStatus !== 'success') {
                return new VerificationResponse(
                    success: false,
                    paymentId: (string) $normalized['paymentId'],
                    status: 'failed',
                    raw: [
                        'callback' => $payload,
                        'auth' => $authResponse,
                    ],
                );
            }

            return new VerificationResponse(
                success: true,
                paymentId: (string) $normalized['paymentId'],
                status: 'success',
                raw: [
                    'callback' => $payload,
                    'auth' => $authResponse,
                ],
            );
        }

        if (isset($payload['iyziEventType']) || isset($payload['iyziReferenceCode'])) {
            $status = strtoupper((string) ($payload['status'] ?? ''));
            $normalizedStatus = $status === 'SUCCESS' ? 'success' : 'failed';

            // TODO: Webhook imzasını doğrula ve auth yanıtıyla ilişkilendir.
            return new VerificationResponse(
                success: $normalizedStatus === 'success',
                paymentId: $payload['paymentId'] ?? null,
                status: $normalizedStatus,
                raw: $payload,
            );
        }

        return new VerificationResponse(
            success: false,
            paymentId: $payload['paymentId'] ?? null,
            status: 'failed',
            raw: $payload,
        );
    }

    /**
     * Iade henüz desteklenmiyor.
     */
    public function refund(RefundPaymentData $data): RefundResponse
    {
        throw new UnsupportedOperationException('refund', 'iyzico');
    }
}
