<?php

declare(strict_types=1);

namespace Voxyfy\AnadoluPay\Gateways\Provider;

use Voxyfy\AnadoluPay\DTO\CreatePaymentData;
use Voxyfy\AnadoluPay\DTO\PaymentResponse;
use Voxyfy\AnadoluPay\DTO\RefundPaymentData;
use Voxyfy\AnadoluPay\DTO\RefundResponse;
use Voxyfy\AnadoluPay\DTO\VerificationResponse;
use Voxyfy\AnadoluPay\DTO\VerifyPaymentData;
use Voxyfy\AnadoluPay\Exceptions\InvalidSignatureException;
use Voxyfy\AnadoluPay\Gateways\Bank\AbstractBankGateway;
use Voxyfy\AnadoluPay\Support\Money;

/**
 * PayTR Ödeme Kuruluşu Driver'ı
 *
 * PayTR bir banka değil ödeme kuruluşudur; tüm bankaların kartlarını tek
 * entegrasyonla kabul eder.
 *
 * Protokol özeti:
 *   - Tüm istekler `paytr_token` alanıyla imzalanır:
 *     hmac_sha256_b64( ilgili alanlar birleşimi + merchant_salt, merchant_key ).
 *   - Bildirim (callback) doğrulaması farklı bir dizgi kullanır:
 *     merchant_oid + merchant_salt + status + total_amount.
 *   - Yapılandırmada `merchant_id` => merchant id, `secret_key` => merchant key,
 *     `password` => merchant salt olarak eşlenir.
 */
class PayTrGateway extends AbstractBankGateway
{
    /**
     * PayTR doğrudan ödeme formu; kart alanları da PayTR'ye POST edilir.
     *
     * @return array<string, mixed>
     */
    protected function build3dFormFields(CreatePaymentData $data): array
    {
        $this->config->require(['merchantId', 'secretKey', 'password']);

        $fields = $this->directPaymentFields($data, secure: true);
        $fields['merchant_ok_url'] = $this->successUrl($data);
        $fields['merchant_fail_url'] = $this->failUrl($data);
        $fields['paytr_token'] = $this->paymentToken($fields);

        return $fields;
    }

    /**
     * PayTR bildirimini doğrular.
     *
     * Not: PayTR bildirimin işlendiğini anlamak için yanıt gövdesinde
     * düz metin `OK` bekler. Webhook rotanızın bunu döndürdüğünden
     * emin olun.
     */
    public function verify(VerifyPaymentData $data): VerificationResponse
    {
        $payload = $data->payload;

        if ($this->config->verifyHash && ! $this->checkCallbackHash($payload)) {
            throw new InvalidSignatureException($this->config->bank, [
                'reason' => 'hash_mismatch',
                'order_id' => $this->extractOrderId($payload),
            ]);
        }

        $approved = $this->pick($payload, ['status']) === 'success';

        return new VerificationResponse(
            success: $approved,
            paymentId: $this->extractOrderId($payload),
            status: $approved ? 'success' : 'failed',
            raw: $payload,
        );
    }

    /**
     * Bildirim imzası: hmac_sha256_b64(
     *   merchant_oid + merchant_salt + status + total_amount, merchant_key ).
     *
     * @param  array<string, mixed>  $payload
     */
    protected function checkCallbackHash(array $payload): bool
    {
        $incoming = $this->pick($payload, ['hash']);

        if ($incoming === null) {
            return false;
        }

        $expected = $this->hmac(
            ($this->pick($payload, ['merchant_oid'], '') ?? '')
            .$this->config->password
            .($this->pick($payload, ['status'], '') ?? '')
            .($this->pick($payload, ['total_amount'], '') ?? '')
        );

        return hash_equals($expected, $incoming);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function is3dAuthSuccess(array $payload): bool
    {
        return $this->pick($payload, ['status']) === 'success';
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    protected function provision(array $payload): array
    {
        // PayTR provizyonu kendisi tamamlar.
        return [];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function mapCallbackResponse(array $payload): VerificationResponse
    {
        $approved = $this->pick($payload, ['status']) === 'success';

        return new VerificationResponse(
            success: $approved,
            paymentId: $this->extractOrderId($payload),
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
        return $this->pick($payload, ['merchant_oid']);
    }

    /**
     * 3D'siz doğrudan ödeme (`non_3d=1`).
     */
    protected function nonSecurePayment(CreatePaymentData $data): PaymentResponse
    {
        $fields = $this->directPaymentFields($data, secure: false);
        $fields['paytr_token'] = $this->paymentToken($fields);

        $response = $this->client->postForm(
            url: rtrim($this->config->endpoint('payment_api'), '/').'/odeme',
            fields: $fields,
        );

        $approved = $this->pick($response, ['status']) === 'success';

        return new PaymentResponse(
            success: $approved,
            paymentId: $data->orderId,
            errorMessage: $approved ? null : $this->pick($response, ['reason', 'err_msg']),
            raw: $response,
            errorCode: $approved ? null : $this->pick($response, ['err_no']),
        );
    }

    /**
     * Tam veya kısmi iade.
     */
    public function refund(RefundPaymentData $data): RefundResponse
    {
        $fields = [
            'merchant_id' => $this->config->merchantId,
            'merchant_oid' => $data->paymentId,
            'return_amount' => ($data->money() ?? Money::fromMinorUnits(0, $data->currency))->toDecimalString(),
        ];

        $fields['paytr_token'] = $this->hmac(implode('', $fields).$this->config->password);

        $response = $this->client->postForm(
            url: rtrim($this->config->endpoint('payment_api'), '/').'/odeme/iade',
            fields: $fields,
        );

        $approved = $this->pick($response, ['status']) === 'success';

        return new RefundResponse(
            success: $approved,
            refundId: $this->pick($response, ['merchant_oid']),
            errorMessage: $approved ? null : $this->pick($response, ['err_msg']),
            raw: $response,
        );
    }

    /**
     * Sipariş durumunu sorgular.
     *
     * @return array<string, mixed>
     */
    public function status(string $orderId): array
    {
        $fields = [
            'merchant_id' => $this->config->merchantId,
            'merchant_oid' => $orderId,
        ];

        $fields['paytr_token'] = $this->hmac(implode('', $fields).$this->config->password);

        return $this->client->postForm(
            url: rtrim($this->config->endpoint('payment_api'), '/').'/odeme/durum',
            fields: $fields,
        );
    }

    /**
     * Ödeme isteğinin ortak alanları.
     *
     * @return array<string, scalar>
     */
    protected function directPaymentFields(CreatePaymentData $data, bool $secure): array
    {
        $card = $this->requireCard($data);
        $customer = $data->customer;
        $installment = $data->installments() > 1 ? $data->installments() : 0;

        return [
            'merchant_id' => $this->config->merchantId,
            'user_ip' => $data->clientIp(),
            'merchant_oid' => $data->orderId,
            'email' => (string) ($customer['email'] ?? ''),
            'payment_amount' => $data->money()->toDecimalString(),
            'installment_count' => $installment,
            'currency' => strtoupper($data->currency) === 'TRY' ? 'TL' : strtoupper($data->currency),
            'non_3d' => $secure ? 0 : 1,
            'sync_mode' => $secure ? 0 : 1,
            'user_name' => (string) ($customer['name'] ?? ''),
            'user_address' => (string) ($customer['address'] ?? ''),
            'user_phone' => (string) ($customer['phone'] ?? $customer['gsm_number'] ?? ''),
            'test_mode' => $this->config->testMode ? 1 : 0,
            'debug_on' => $this->config->testMode ? 1 : 0,
            'client_lang' => strtolower($data->lang) === 'en' ? 'en' : 'tr',
            'user_basket' => $this->basket($data),
            'payment_type' => 'card',
            'cc_owner' => $card->holderName ?? '',
            'card_number' => $card->number,
            'expiry_month' => $card->expireMonth,
            'expiry_year' => $card->expireYearShort(),
            'cvv' => $card->cvv,
        ];
    }

    /**
     * Ödeme isteğinin `paytr_token` imzası.
     *
     * @param  array<string, scalar>  $fields
     */
    protected function paymentToken(array $fields): string
    {
        $order = [
            'merchant_id',
            'user_ip',
            'merchant_oid',
            'email',
            'payment_amount',
            'payment_type',
            'installment_count',
            'currency',
            'test_mode',
            'non_3d',
        ];

        $value = '';

        foreach ($order as $field) {
            $value .= (string) ($fields[$field] ?? '');
        }

        return $this->hmac($value.$this->config->password);
    }

    /**
     * Sepet içeriğini PayTR'nin beklediği base64 JSON formatına çevirir.
     */
    protected function basket(CreatePaymentData $data): string
    {
        $items = $data->metadata['basket'] ?? null;

        if (! is_array($items) || $items === []) {
            $items = [[
                'Sipariş '.$data->orderId,
                $data->money()->toDecimalString(),
                1,
            ]];
        }

        return base64_encode((string) json_encode($items, JSON_UNESCAPED_UNICODE));
    }

    /**
     * PayTR imzası: base64( hmac_sha256( value, merchant_key ) ).
     */
    protected function hmac(string $value): string
    {
        return base64_encode(hash_hmac('sha256', $value, $this->config->secretKey, true));
    }
}
