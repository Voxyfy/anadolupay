<?php

declare(strict_types=1);

namespace Voxyfy\AnadoluPay\Gateways\Provider;

use Voxyfy\AnadoluPay\DTO\CreatePaymentData;
use Voxyfy\AnadoluPay\DTO\PaymentResponse;
use Voxyfy\AnadoluPay\DTO\RefundPaymentData;
use Voxyfy\AnadoluPay\DTO\RefundResponse;
use Voxyfy\AnadoluPay\DTO\VerificationResponse;
use Voxyfy\AnadoluPay\Gateways\Bank\AbstractBankGateway;
use Voxyfy\AnadoluPay\Support\Bank\Currency;

/**
 * Akbank Sanal POS (yeni JSON API) Driver'ı
 *
 * Akbank'ın Asseco tabanlı eski sanal POS'unun yerini alan REST/JSON
 * API'sidir. Eski kurulum için `AssecoGateway` kullanılır.
 *
 * Protokol özeti:
 *   - Kimlik doğrulama `merchantSafeId` + `terminalSafeId` çiftiyle yapılır.
 *   - Her API isteği, gövdenin tamamı üzerinden hesaplanan
 *     `auth-hash` başlığıyla imzalanır: hmac_sha512_b64(body, secretKey).
 *   - 3D formu ayrı bir imza kullanır; alanlar sabit bir sırada ayraçsız
 *     birleştirilir.
 *   - Başarı kodu `VPS-0000`.
 */
class AkbankPosGateway extends AbstractBankGateway
{
    /** Başarılı işlem kodu. */
    protected const SUCCESS_CODE = 'VPS-0000';

    /** API sürümü. */
    protected const API_VERSION = '1.00';

    /** Ödeme (satış) işlem kodları. */
    protected const TXN_CODE_3D = '3000';

    protected const TXN_CODE_NON_SECURE = '1000';

    /**
     * @return array<string, mixed>
     */
    protected function build3dFormFields(CreatePaymentData $data): array
    {
        $this->config->require(['merchantId', 'terminalId', 'secretKey']);

        $inputs = [
            'paymentModel' => $this->paymentModelCode($data->paymentModel),
            'txnCode' => self::TXN_CODE_3D,
            'merchantSafeId' => $this->config->merchantId,
            'terminalSafeId' => $this->config->terminalId,
            'orderId' => $data->orderId,
            'lang' => strtoupper($data->lang) === 'EN' ? 'EN' : 'TR',
            'amount' => $this->formatAmount($data->amount),
            'currencyCode' => Currency::numeric($data->currency),
            'installCount' => (string) $data->installments(),
            'okUrl' => $this->successUrl($data),
            'failUrl' => $this->failUrl($data),
            'randomNumber' => $this->randomString(128),
            'requestDateTime' => $this->requestDateTime(),
        ];

        // 3D Host modelinde kart bilgileri Akbank'ın sayfasında toplanır.
        if ($data->paymentModel !== CreatePaymentData::MODEL_3D_HOST) {
            $card = $this->requireCard($data);

            $inputs['creditCard'] = $card->number;
            $inputs['expiredDate'] = $card->expiry('my');
            $inputs['cvv'] = $card->cvv;
        }

        $inputs['hash'] = $this->create3dHash($inputs);

        return $inputs;
    }

    /**
     * Dönüş imzası, `hashParams` alanında artı ile ayrılan alanlardan üretilir.
     *
     * @param  array<string, mixed>  $payload
     */
    protected function checkCallbackHash(array $payload): bool
    {
        $incoming = $this->pick($payload, ['hash']);
        $hashParams = $this->pick($payload, ['hashParams']);

        if ($incoming === null || $hashParams === null) {
            return false;
        }

        $values = '';

        foreach (explode('+', $hashParams) as $field) {
            if ($field === '') {
                continue;
            }

            $values .= $this->pick($payload, [$field], '') ?? '';
        }

        return hash_equals($this->hmac($values), $incoming);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function is3dAuthSuccess(array $payload): bool
    {
        return $this->pick($payload, ['responseCode']) === self::SUCCESS_CODE;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function paymentModelOf(array $payload): string
    {
        return match ($this->pick($payload, ['paymentModel'])) {
            '3D_PAY' => CreatePaymentData::MODEL_3D_PAY,
            '3D_PAY_HOSTING' => CreatePaymentData::MODEL_3D_HOST,
            default => CreatePaymentData::MODEL_3D_SECURE,
        };
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    protected function provision(array $payload): array
    {
        return $this->postJson([
            'terminal' => [
                'merchantSafeId' => $this->config->merchantId,
                'terminalSafeId' => $this->config->terminalId,
            ],
            'version' => self::API_VERSION,
            'txnCode' => self::TXN_CODE_NON_SECURE,
            'requestDateTime' => $this->requestDateTime(),
            'randomNumber' => $this->randomString(128),
            'order' => [
                'orderId' => $this->extractOrderId($payload) ?? '',
            ],
            'transaction' => [
                'amount' => $this->pick($payload, ['amount'], '') ?? '',
                'currencyCode' => (int) ($this->pick($payload, ['currencyCode'], '949') ?? '949'),
                'motoInd' => 0,
                'installCount' => (int) ($this->pick($payload, ['installCount'], '1') ?? '1'),
            ],
            'secureTransaction' => [
                'secureId' => $this->pick($payload, ['secureId'], '') ?? '',
                'secureEcomInd' => $this->pick($payload, ['secureEcomInd'], '') ?? '',
                'secureData' => $this->pick($payload, ['secureData'], '') ?? '',
                'secureMd' => $this->pick($payload, ['secureMd'], '') ?? '',
            ],
            'customer' => [
                'ipAddress' => $this->pick($payload, ['ipAddress'], '127.0.0.1') ?? '127.0.0.1',
            ],
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function mapCallbackResponse(array $payload): VerificationResponse
    {
        $approved = $this->is3dAuthSuccess($payload);

        return new VerificationResponse(
            success: $approved,
            paymentId: $this->pick($payload, ['authCode', 'rrn']) ?? $this->extractOrderId($payload),
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
        $approved = $this->pick($provision, ['responseCode']) === self::SUCCESS_CODE;

        return new VerificationResponse(
            success: $approved,
            paymentId: $this->transactionField($provision, 'rrn')
                ?? $this->transactionField($provision, 'authCode')
                ?? $this->extractOrderId($payload),
            status: $approved ? 'success' : 'failed',
            raw: ['callback' => $payload, 'provision' => $provision],
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function extractOrderId(array $payload): ?string
    {
        $orderId = $payload['order']['orderId'] ?? null;

        if (is_scalar($orderId) && (string) $orderId !== '') {
            return (string) $orderId;
        }

        return $this->pick($payload, ['orderId']);
    }

    /**
     * 3D'siz doğrudan provizyon.
     */
    protected function nonSecurePayment(CreatePaymentData $data): PaymentResponse
    {
        $card = $this->requireCard($data);

        $response = $this->postJson([
            'terminal' => [
                'merchantSafeId' => $this->config->merchantId,
                'terminalSafeId' => $this->config->terminalId,
            ],
            'version' => self::API_VERSION,
            'txnCode' => self::TXN_CODE_NON_SECURE,
            'requestDateTime' => $this->requestDateTime(),
            'randomNumber' => $this->randomString(128),
            'card' => [
                'cardNumber' => $card->number,
                'cvv2' => $card->cvv,
                'expireDate' => $card->expiry('my'),
            ],
            'order' => ['orderId' => $data->orderId],
            'transaction' => [
                'amount' => $this->formatAmount($data->amount),
                'currencyCode' => (int) Currency::numeric($data->currency),
                'motoInd' => 0,
                'installCount' => $data->installments(),
            ],
            'customer' => ['ipAddress' => $data->clientIp()],
        ]);

        $approved = $this->pick($response, ['responseCode']) === self::SUCCESS_CODE;

        return new PaymentResponse(
            success: $approved,
            paymentId: $this->transactionField($response, 'rrn') ?? $data->orderId,
            errorMessage: $approved ? null : $this->pick($response, ['hostMessage', 'responseMessage']),
            raw: $response,
            errorCode: $approved ? null : $this->pick($response, ['responseCode']),
        );
    }

    /**
     * Tam veya kısmi iade (txnCode 1002).
     */
    public function refund(RefundPaymentData $data): RefundResponse
    {
        return $this->mapReversal($this->postJson([
            'terminal' => [
                'merchantSafeId' => $this->config->merchantId,
                'terminalSafeId' => $this->config->terminalId,
            ],
            'version' => self::API_VERSION,
            'txnCode' => '1002',
            'requestDateTime' => $this->requestDateTime(),
            'randomNumber' => $this->randomString(128),
            'order' => ['orderId' => $data->paymentId],
            'transaction' => [
                'amount' => $this->formatAmount($data->amount ?? 0.0),
                'currencyCode' => (int) Currency::numeric($data->currency),
            ],
            'customer' => ['ipAddress' => (string) ($data->meta('ip') ?? '127.0.0.1')],
        ]));
    }

    /**
     * Gün sonu öncesi işlem iptali (txnCode 1003).
     */
    public function cancel(RefundPaymentData $data): RefundResponse
    {
        return $this->mapReversal($this->postJson([
            'terminal' => [
                'merchantSafeId' => $this->config->merchantId,
                'terminalSafeId' => $this->config->terminalId,
            ],
            'version' => self::API_VERSION,
            'txnCode' => '1003',
            'requestDateTime' => $this->requestDateTime(),
            'randomNumber' => $this->randomString(128),
            'order' => ['orderId' => $data->paymentId],
        ]));
    }

    /**
     * @param  array<string, mixed>  $response
     */
    protected function mapReversal(array $response): RefundResponse
    {
        $approved = $this->pick($response, ['responseCode']) === self::SUCCESS_CODE;

        return new RefundResponse(
            success: $approved,
            refundId: $this->transactionField($response, 'rrn'),
            errorMessage: $approved ? null : $this->pick($response, ['hostMessage', 'responseMessage']),
            raw: $response,
        );
    }

    /**
     * 3D form imzası.
     *
     * @param  array<string, mixed>  $inputs
     */
    protected function create3dHash(array $inputs): string
    {
        $order = [
            'paymentModel', 'txnCode', 'merchantSafeId', 'terminalSafeId', 'orderId',
            'lang', 'amount', 'ccbRewardAmount', 'pcbRewardAmount', 'xcbRewardAmount',
            'currencyCode', 'installCount', 'okUrl', 'failUrl', 'emailAddress',
            'subMerchantId', 'creditCard', 'expiredDate', 'cvv', 'randomNumber',
            'requestDateTime', 'b2bIdentityNumber',
        ];

        $value = '';

        foreach ($order as $field) {
            $value .= (string) ($inputs[$field] ?? '');
        }

        return $this->hmac($value);
    }

    protected function hmac(string $value): string
    {
        return base64_encode(hash_hmac('sha512', $value, $this->config->secretKey, true));
    }

    /**
     * Akbank tutarları iki ondalıklı ondalık ayraçla bekler.
     */
    protected function formatAmount(float $amount): string
    {
        return number_format($amount, 2, '.', '');
    }

    protected function requestDateTime(): string
    {
        return date('Y-m-d\TH:i:s').'.000';
    }

    protected function paymentModelCode(string $paymentModel): string
    {
        return match ($paymentModel) {
            CreatePaymentData::MODEL_3D_PAY => '3D_PAY',
            CreatePaymentData::MODEL_3D_HOST => '3D_PAY_HOSTING',
            CreatePaymentData::MODEL_NON_SECURE => 'PAY_HOSTING',
            default => '3D',
        };
    }

    /**
     * @param  array<string, mixed>  $response
     */
    protected function transactionField(array $response, string $field): ?string
    {
        $value = $response['transaction'][$field] ?? null;

        return is_scalar($value) && (string) $value !== '' && (string) $value !== '0'
            ? (string) $value
            : null;
    }

    /**
     * Akbank her isteği gövdenin tamamı üzerinden hesaplanan
     * `auth-hash` başlığıyla imzalar.
     *
     * @param  array<string, mixed>  $request
     * @return array<string, mixed>
     */
    protected function postJson(array $request): array
    {
        $body = json_encode($request, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $body = $body === false ? '{}' : $body;

        return $this->client->send(
            url: rtrim($this->config->endpoint('payment_api'), '/').'/transaction/process',
            body: $body,
            headers: [
                'Content-Type' => 'application/json',
                'auth-hash' => $this->hmac($body),
            ],
        );
    }
}
