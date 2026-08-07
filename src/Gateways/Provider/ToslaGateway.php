<?php

declare(strict_types=1);

namespace Voxyfy\AnadoluPay\Gateways\Provider;

use Voxyfy\AnadoluPay\DTO\CreatePaymentData;
use Voxyfy\AnadoluPay\DTO\PaymentResponse;
use Voxyfy\AnadoluPay\DTO\RefundPaymentData;
use Voxyfy\AnadoluPay\DTO\RefundResponse;
use Voxyfy\AnadoluPay\DTO\VerificationResponse;
use Voxyfy\AnadoluPay\Exceptions\PaymentFailedException;
use Voxyfy\AnadoluPay\Gateways\Bank\AbstractBankGateway;
use Voxyfy\AnadoluPay\Support\Bank\Currency;
use Voxyfy\AnadoluPay\Support\Money;

/**
 * Tosla (AkÖde A.Ş.) Driver'ı
 *
 * Protokol özeti:
 *   - Akış iki adımlıdır: önce `threeDPayment` ucundan bir
 *     `ThreeDSessionId` alınır, sonra kart bilgileriyle birlikte bu oturum
 *     kimliği ödeme formuna POST edilir.
 *   - İstek imzası: sha512_b64( secretKey + clientId + apiUser + rnd + timeSpan ).
 *   - Dönüş imzası, bankanın `HashParameters` alanında virgülle bildirdiği
 *     alanların değerleri üzerinden hesaplanır (secretKey öne eklenir).
 *   - Tutarlar kuruş cinsindendir, başarı kodu `00`dır.
 */
class ToslaGateway extends AbstractBankGateway
{
    /** Başarılı işlem kodu. */
    protected const SUCCESS_CODE = '00';

    /**
     * Tosla'da form alanları, önce alınan 3D oturumundan üretilir.
     */
    public function createPayment(CreatePaymentData $data): PaymentResponse
    {
        if ($data->paymentModel === CreatePaymentData::MODEL_NON_SECURE) {
            return $this->nonSecurePayment($data);
        }

        $sessionId = $this->openThreeDSession($data);

        // 3D Host: müşteri Tosla'nın kendi ödeme sayfasına GET ile yönlendirilir.
        if ($data->paymentModel === CreatePaymentData::MODEL_3D_HOST) {
            return new PaymentResponse(
                success: true,
                paymentId: $data->orderId,
                redirectUrl: rtrim($this->config->endpoint('gateway_3d_host'), '/').'/'.$sessionId,
                raw: ['three_d_session_id' => $sessionId],
            );
        }

        $card = $this->requireCard($data);

        return new PaymentResponse(
            success: true,
            paymentId: $data->orderId,
            raw: ['three_d_session_id' => $sessionId],
            formAction: $this->config->endpoint('gateway_3d'),
            formFields: [
                'ThreeDSessionId' => $sessionId,
                'CardHolderName' => $card->holderName ?? '',
                'CardNo' => $card->number,
                'ExpireDate' => $card->expiry('m/y'),
                'Cvv' => $card->cvv,
            ],
        );
    }

    /**
     * 3D ödeme oturumu açar ve `ThreeDSessionId` döndürür.
     *
     * @throws PaymentFailedException Oturum açılamazsa
     */
    protected function openThreeDSession(CreatePaymentData $data): string
    {
        $this->config->require(['merchantId', 'username', 'secretKey']);

        $request = $this->accountData() + [
            'callbackUrl' => $this->successUrl($data),
            'orderId' => $data->orderId,
            'amount' => $data->money()->minorUnits,
            'currency' => (int) Currency::numeric($data->currency),
            'installmentCount' => $data->installments() > 1 ? $data->installments() : 0,
            'rnd' => $this->randomString(),
            'timeSpan' => $this->timeSpan(),
        ];

        $request['hash'] = $this->createHash($request);

        $response = $this->postJson('threeDPayment', $request);
        $sessionId = $this->pick($response, ['ThreeDSessionId']);

        if ($sessionId === null) {
            throw new PaymentFailedException(
                message: (string) ($this->pick($response, ['Message']) ?? 'Tosla 3D oturumu açılamadı.'),
                context: ['response' => $response],
            );
        }

        return $sessionId;
    }

    /**
     * @return array<string, mixed>
     */
    protected function build3dFormFields(CreatePaymentData $data): array
    {
        // createPayment() oturum kimliğine ihtiyaç duyduğu için bu yolu kullanmaz.
        return ['ThreeDSessionId' => $this->openThreeDSession($data)];
    }

    /**
     * Dönüş imzası `HashParameters` alanında virgülle bildirilir.
     *
     * @param  array<string, mixed>  $payload
     */
    protected function checkCallbackHash(array $payload): bool
    {
        $incoming = $this->pick($payload, ['Hash']);
        $hashParams = $this->pick($payload, ['HashParameters']);

        if ($incoming === null || $hashParams === null) {
            return false;
        }

        // ClientId ve ApiUser dönüşte gelmez; yapılandırmadan tamamlanır.
        $payload += [
            'ClientId' => $this->config->merchantId,
            'ApiUser' => $this->config->username,
        ];

        $values = '';

        foreach (explode(',', $hashParams) as $field) {
            $field = trim($field);

            if ($field === '') {
                continue;
            }

            $values .= $this->pick($payload, [$field], '') ?? '';
        }

        return hash_equals($this->hash($this->config->secretKey.$values), $incoming);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function is3dAuthSuccess(array $payload): bool
    {
        return $this->pick($payload, ['BankResponseCode']) === self::SUCCESS_CODE;
    }

    /**
     * Tosla 3D Pay/3D Host modellerinde provizyonu kendisi tamamlar.
     *
     * @param  array<string, mixed>  $payload
     */
    protected function requiresProvision(array $payload): bool
    {
        return false;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    protected function provision(array $payload): array
    {
        return [];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function mapCallbackResponse(array $payload): VerificationResponse
    {
        $approved = $this->is3dAuthSuccess($payload);

        return new VerificationResponse(
            success: $approved,
            paymentId: $this->pick($payload, ['TransactionId']) ?? $this->extractOrderId($payload),
            status: $approved ? 'success' : 'failed',
            raw: $payload,
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $provision
     */
    protected function mapProvisionResponse(array $payload, array $provision): VerificationResponse
    {
        return $this->mapCallbackResponse($payload);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function extractOrderId(array $payload): ?string
    {
        return $this->pick($payload, ['OrderId']);
    }

    /**
     * 3D'siz doğrudan ödeme.
     */
    protected function nonSecurePayment(CreatePaymentData $data): PaymentResponse
    {
        $card = $this->requireCard($data);

        $request = $this->accountData() + [
            'orderId' => $data->orderId,
            'amount' => $data->money()->minorUnits,
            'currency' => (int) Currency::numeric($data->currency),
            'installmentCount' => $data->installments() > 1 ? $data->installments() : 0,
            'rnd' => $this->randomString(),
            'timeSpan' => $this->timeSpan(),
            'cardHolderName' => $card->holderName ?? '',
            'cardNo' => $card->number,
            'expireDate' => $card->expiry('my'),
            'cvv' => $card->cvv,
        ];

        $request['hash'] = $this->createHash($request);

        $response = $this->postJson('Payment', $request);
        $approved = $this->pick($response, ['BankResponseCode']) === self::SUCCESS_CODE;

        return new PaymentResponse(
            success: $approved,
            paymentId: $this->pick($response, ['TransactionId']) ?? $data->orderId,
            errorMessage: $approved ? null : $this->pick($response, ['BankResponseMessage', 'Message']),
            raw: $response,
            errorCode: $approved ? null : $this->pick($response, ['BankResponseCode', 'Code']),
        );
    }

    /**
     * Tam veya kısmi iade.
     */
    public function refund(RefundPaymentData $data): RefundResponse
    {
        $request = $this->accountData() + [
            'orderId' => $data->paymentId,
            'rnd' => $this->randomString(),
            'timeSpan' => $this->timeSpan(),
        ];

        if (($amount = $data->money()) !== null) {
            $request['amount'] = $amount->minorUnits;
        }

        $request['hash'] = $this->createHash($request);

        return $this->mapReversal($this->postJson('refund', $request));
    }

    /**
     * Gün sonu öncesi işlem iptali.
     */
    public function cancel(RefundPaymentData $data): RefundResponse
    {
        $request = $this->accountData() + [
            'orderId' => $data->paymentId,
            'rnd' => $this->randomString(),
            'timeSpan' => $this->timeSpan(),
        ];

        $request['hash'] = $this->createHash($request);

        return $this->mapReversal($this->postJson('void', $request));
    }

    /**
     * @param  array<string, mixed>  $response
     */
    protected function mapReversal(array $response): RefundResponse
    {
        $approved = $this->pick($response, ['BankResponseCode']) === self::SUCCESS_CODE;

        return new RefundResponse(
            success: $approved,
            refundId: $this->pick($response, ['TransactionId']),
            errorMessage: $approved ? null : $this->pick($response, ['BankResponseMessage', 'Message']),
            raw: $response,
        );
    }

    /**
     * İstek imzası: sha512_b64( secretKey + clientId + apiUser + rnd + timeSpan ).
     *
     * @param  array<string, mixed>  $request
     */
    protected function createHash(array $request): string
    {
        return $this->hash(implode('', [
            $this->config->secretKey,
            (string) ($request['clientId'] ?? ''),
            (string) ($request['apiUser'] ?? ''),
            (string) ($request['rnd'] ?? ''),
            (string) ($request['timeSpan'] ?? ''),
        ]));
    }

    protected function hash(string $value): string
    {
        return base64_encode(hash('sha512', $value, true));
    }

    /**
     * Tosla tutarları kuruş cinsinden tam sayı olarak bekler.
     */
    protected function formatAmount(Money $money): string
    {
        return $money->toMinorUnitsString();
    }

    /**
     * İsteğin zaman damgası (YmdHis).
     */
    protected function timeSpan(): string
    {
        return date('YmdHis');
    }

    /**
     * @return array<string, string>
     */
    protected function accountData(): array
    {
        return [
            'clientId' => $this->config->merchantId,
            'apiUser' => $this->config->username,
        ];
    }

    /**
     * @param  array<string, mixed>  $request
     * @return array<string, mixed>
     */
    protected function postJson(string $operation, array $request): array
    {
        return $this->client->postJson(
            url: rtrim($this->config->endpoint('payment_api'), '/').'/'.$operation,
            data: $request,
        );
    }
}
