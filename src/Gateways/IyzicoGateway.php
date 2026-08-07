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
use Voxyfy\AnadoluPay\Support\IyzicoHttpClient;
use Voxyfy\AnadoluPay\Support\IyzicoMapper;
use Voxyfy\AnadoluPay\Support\IyzicoSignatureValidator;
use Voxyfy\AnadoluPay\Support\LoggerResolver;

/**
 * iyzico Ödeme Geçidi
 *
 * Bankaların aksine iyzico 3D Secure adımında form alanları değil, base64
 * kodlanmış hazır bir HTML sayfası (`threeDSHtmlContent`) döner.
 *
 * Her yanıt ve callback, `IyzicoSignatureValidator` ile doğrulanır; imza
 * eşleşmezse `InvalidSignatureException` fırlatılır.
 */
class IyzicoGateway implements PaymentGatewayInterface
{
    /** iyzico'nun başarılı işlemler için döndürdüğü durum. */
    protected const STATUS_SUCCESS = 'success';

    /** Tam 3D doğrulaması. */
    protected const MD_STATUS_AUTHENTICATED = '1';

    public function __construct(
        private readonly IyzicoHttpClient $client,
        private readonly IyzicoMapper $mapper,
        private readonly IyzicoSignatureValidator $validator,
    ) {}

    public static function fromConfig(): self
    {
        $config = config('anadolupay.iyzico', []);
        $secretKey = (string) ($config['secret_key'] ?? '');

        $client = new IyzicoHttpClient(
            baseUrl: (string) ($config['base_url'] ?? ''),
            apiKey: (string) ($config['api_key'] ?? ''),
            secretKey: $secretKey,
            timeout: (int) ($config['timeout'] ?? 30),
            logger: LoggerResolver::resolve(),
        );

        $validator = new IyzicoSignatureValidator(
            secretKey: $secretKey,
            enabled: (bool) ($config['validate_signature'] ?? true),
            webhookSignatureHeader: (string) ($config['webhook_signature_header'] ?? 'x-iyz-signature-v3'),
            signatureParam: (string) ($config['signature_param'] ?? 'signature'),
        );

        return new self($client, new IyzicoMapper, $validator);
    }

    /**
     * 3DS ödemeyi başlatır.
     *
     * Dönen `htmlContent` doğrudan tarayıcıya basılabilir; `toHtmlForm()`
     * bunu sizin için yapar.
     */
    public function createPayment(CreatePaymentData $data): PaymentResponse
    {
        $callbackUrl = (string) config('anadolupay.iyzico.callback_url');
        $payload = $this->mapper->to3dsInitializePayload($data, $callbackUrl);
        $response = $this->client->post('/payment/3dsecure/initialize', $payload);

        if ($this->statusOf($response) !== self::STATUS_SUCCESS) {
            throw new PaymentFailedException(
                message: (string) ($response['errorMessage'] ?? 'iyzico 3DS başlatma başarısız.'),
                context: [
                    'errorCode' => $response['errorCode'] ?? null,
                    'response' => $response,
                ],
            );
        }

        $this->validator->validateInitializeResponse($response);

        // threeDSHtmlContent base64 kodlanmış bir HTML belgesidir.
        $encodedHtml = $response['threeDSHtmlContent'] ?? null;
        $html = is_string($encodedHtml) ? base64_decode($encodedHtml, true) : false;

        return new PaymentResponse(
            success: true,
            paymentId: isset($response['paymentId']) ? (string) $response['paymentId'] : null,
            raw: $response,
            htmlContent: $html === false ? null : $html,
        );
    }

    /**
     * 3DS callback'ini veya webhook bildirimini doğrular.
     */
    public function verify(VerifyPaymentData $data): VerificationResponse
    {
        $payload = $data->payload;

        if ($this->isWebhook($payload)) {
            return $this->verifyWebhook($payload, $data->headers);
        }

        return $this->verifyCallback($payload);
    }

    /**
     * 3DS yönlendirme dönüşünü doğrular ve provizyonu tamamlar.
     *
     * @param  array<string, mixed>  $payload
     */
    protected function verifyCallback(array $payload): VerificationResponse
    {
        $this->validator->validateCallback($payload);

        $normalized = $this->mapper->normalizeCallbackStatus($payload);

        if (! $normalized['success']) {
            return new VerificationResponse(
                success: false,
                paymentId: $normalized['paymentId'],
                status: 'failed',
                raw: ['callback' => $payload],
            );
        }

        $authResponse = $this->client->post('/payment/3dsecure/auth', $this->mapper->to3dsAuthPayload(
            (string) $normalized['paymentId'],
            $normalized['conversationData'] !== null ? (string) $normalized['conversationData'] : null,
        ));

        if ($this->statusOf($authResponse) !== self::STATUS_SUCCESS) {
            return new VerificationResponse(
                success: false,
                paymentId: (string) $normalized['paymentId'],
                status: 'failed',
                raw: ['callback' => $payload, 'auth' => $authResponse],
            );
        }

        $this->validator->validateAuthResponse($authResponse);

        return new VerificationResponse(
            success: true,
            paymentId: (string) $normalized['paymentId'],
            status: 'success',
            raw: ['callback' => $payload, 'auth' => $authResponse],
        );
    }

    /**
     * Webhook bildirimini doğrular.
     *
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $headers
     */
    protected function verifyWebhook(array $payload, array $headers): VerificationResponse
    {
        $this->validator->validateWebhook($payload, $headers);

        $approved = strtoupper((string) ($payload['status'] ?? '')) === 'SUCCESS';

        return new VerificationResponse(
            success: $approved,
            paymentId: isset($payload['paymentId']) ? (string) $payload['paymentId'] : null,
            status: $approved ? 'success' : 'failed',
            raw: $payload,
        );
    }

    /**
     * Tam veya kısmi iade.
     *
     * `/v2/payment/refund` ucunu kullanır; bu uç iadeyi ödeme numarasıyla
     * eşler. İade tutarı verilmezse iyzico ödemenin tamamını iade eder.
     *
     * İşlem (kalem) bazlı iade için `metadata['ip']` ve
     * `metadata['conversation_id']` alanlarını geçebilirsiniz.
     */
    public function refund(RefundPaymentData $data): RefundResponse
    {
        $payload = array_filter([
            'locale' => (string) ($data->meta('locale') ?? 'tr'),
            'conversationId' => (string) ($data->meta('conversation_id') ?? $data->paymentId),
            'paymentId' => $data->paymentId,
            'price' => $data->money()?->toDecimalString(),
            'currency' => strtoupper($data->currency),
            'ip' => (string) ($data->meta('ip') ?? '127.0.0.1'),
            'reason' => $data->reason,
        ], static fn (mixed $value): bool => $value !== null && $value !== '');

        $response = $this->client->post('/v2/payment/refund', $payload);

        if ($this->statusOf($response) !== self::STATUS_SUCCESS) {
            return new RefundResponse(
                success: false,
                refundId: null,
                errorMessage: (string) ($response['errorMessage'] ?? 'iyzico iade işlemi başarısız.'),
                raw: $response,
            );
        }

        $this->validator->validateRefundResponse($response);

        return new RefundResponse(
            success: true,
            refundId: isset($response['paymentId']) ? (string) $response['paymentId'] : null,
            raw: $response,
        );
    }

    /**
     * Gelen verinin webhook bildirimi olup olmadığını belirler.
     *
     * @param  array<string, mixed>  $payload
     */
    protected function isWebhook(array $payload): bool
    {
        return isset($payload['iyziEventType']) || isset($payload['iyziReferenceCode']);
    }

    /**
     * @param  array<string, mixed>  $response
     */
    protected function statusOf(array $response): string
    {
        return strtolower((string) ($response['status'] ?? ''));
    }
}
