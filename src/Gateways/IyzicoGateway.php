<?php

declare(strict_types=1);

namespace Voxyfy\AnadoluPay\Gateways;

use Illuminate\Support\Facades\Event;
use Throwable;
use Voxyfy\AnadoluPay\Contracts\PaymentGatewayInterface;
use Voxyfy\AnadoluPay\Contracts\SupportsBinQuery;
use Voxyfy\AnadoluPay\Contracts\SupportsInstallmentQuery;
use Voxyfy\AnadoluPay\Contracts\SupportsStatusQuery;
use Voxyfy\AnadoluPay\DTO\BinResponse;
use Voxyfy\AnadoluPay\DTO\CreatePaymentData;
use Voxyfy\AnadoluPay\DTO\InstallmentOption;
use Voxyfy\AnadoluPay\DTO\PaymentResponse;
use Voxyfy\AnadoluPay\DTO\RefundPaymentData;
use Voxyfy\AnadoluPay\DTO\RefundResponse;
use Voxyfy\AnadoluPay\DTO\StatusResponse;
use Voxyfy\AnadoluPay\DTO\VerificationResponse;
use Voxyfy\AnadoluPay\DTO\VerifyPaymentData;
use Voxyfy\AnadoluPay\Events\PaymentFailed;
use Voxyfy\AnadoluPay\Events\PaymentInitiated;
use Voxyfy\AnadoluPay\Events\PaymentVerified;
use Voxyfy\AnadoluPay\Events\RefundIssued;
use Voxyfy\AnadoluPay\Exceptions\PaymentFailedException;
use Voxyfy\AnadoluPay\Support\Bank\OrderStatus;
use Voxyfy\AnadoluPay\Support\IdempotencyGuard;
use Voxyfy\AnadoluPay\Support\IyzicoHttpClient;
use Voxyfy\AnadoluPay\Support\IyzicoMapper;
use Voxyfy\AnadoluPay\Support\IyzicoSignatureValidator;
use Voxyfy\AnadoluPay\Support\LoggerResolver;
use Voxyfy\AnadoluPay\Support\Money;

/**
 * iyzico Ödeme Geçidi
 *
 * Bankaların aksine iyzico 3D Secure adımında form alanları değil, base64
 * kodlanmış hazır bir HTML sayfası (`threeDSHtmlContent`) döner.
 *
 * Her yanıt ve callback, `IyzicoSignatureValidator` ile doğrulanır; imza
 * eşleşmezse `InvalidSignatureException` fırlatılır.
 */
class IyzicoGateway implements PaymentGatewayInterface, SupportsBinQuery, SupportsInstallmentQuery, SupportsStatusQuery
{
    /** iyzico'nun başarılı işlemler için döndürdüğü durum. */
    protected const STATUS_SUCCESS = 'success';

    /** Tam 3D doğrulaması. */
    protected const MD_STATUS_AUTHENTICATED = '1';

    public function __construct(
        private readonly IyzicoHttpClient $client,
        private readonly IyzicoMapper $mapper,
        private readonly IyzicoSignatureValidator $validator,
        private readonly ?IdempotencyGuard $idempotency = null,
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

        return new self($client, new IyzicoMapper, $validator, IdempotencyGuard::fromConfig());
    }

    /**
     * 3DS ödemeyi başlatır.
     *
     * Dönen `htmlContent` doğrudan tarayıcıya basılabilir; `toHtmlForm()`
     * bunu sizin için yapar.
     */
    public function createPayment(CreatePaymentData $data): PaymentResponse
    {
        $this->idempotency?->acquire('iyzico', $data->orderId);

        try {
            $response = $this->initialize($data);
        } catch (Throwable $exception) {
            $this->idempotency?->release('iyzico', $data->orderId);
            $this->dispatch(PaymentFailed::from('iyzico', $data->orderId, $exception));

            throw $exception;
        }

        $this->dispatch(PaymentInitiated::from('iyzico', $data, $response));

        return $response;
    }

    /**
     * 3DS başlatma isteğini gönderir ve HTML içeriğini çözer.
     */
    protected function initialize(CreatePaymentData $data): PaymentResponse
    {
        // Sipariş kendi dönüş adresini bildirmişse ona öncelik veriyoruz;
        // config'teki değer yalnızca varsayılandır. Diğer tüm driver'lar
        // `successUrl` alanını kullanıyor, iyzico'nun ayrışması sürprizdi.
        $callbackUrl = $data->successUrl ?? (string) config('anadolupay.iyzico.callback_url');

        if ($callbackUrl === '') {
            throw new PaymentFailedException(
                message: 'iyzico için dönüş adresi gerekli: CreatePaymentData::$successUrl ya da anadolupay.iyzico.callback_url tanımlayın.',
                context: ['order_id' => $data->orderId],
            );
        }

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

        try {
            $response = $this->isWebhook($payload)
                ? $this->verifyWebhook($payload, $data->headers)
                : $this->verifyCallback($payload);
        } catch (Throwable $exception) {
            $this->dispatch(PaymentFailed::from(
                'iyzico',
                isset($payload['conversationId']) ? (string) $payload['conversationId'] : null,
                $exception,
            ));

            throw $exception;
        }

        $this->dispatch(PaymentVerified::from(
            'iyzico',
            $response,
            isset($payload['conversationId']) ? (string) $payload['conversationId'] : null,
        ));

        return $response;
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
        try {
            $response = $this->performRefund($data);
        } catch (Throwable $exception) {
            $this->dispatch(PaymentFailed::from('iyzico', $data->paymentId, $exception));

            throw $exception;
        }

        $this->dispatch(RefundIssued::from('iyzico', $data, $response));

        return $response;
    }

    /**
     * İade isteğini gönderir ve yanıt imzasını doğrular.
     */
    protected function performRefund(RefundPaymentData $data): RefundResponse
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
     * Event yayınlar. Yapılandırmadan kapatılabilir.
     */
    protected function dispatch(object $event): void
    {
        if ((bool) config('anadolupay.events.enabled', true)) {
            Event::dispatch($event);
        }
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

    /**
     * Ödeme durumunu sorgular (`/payment/detail`).
     *
     * iyzico sorguyu sipariş numarasıyla (`paymentConversationId`) ya da
     * kendi ödeme numarasıyla yapabilir; ikincisi için
     * `$context['payment_id']` geçin.
     */
    public function status(string $orderId, array $context = []): StatusResponse
    {
        $payload = array_filter([
            'locale' => (string) ($context['locale'] ?? 'tr'),
            'conversationId' => $orderId,
            'paymentConversationId' => $orderId,
            'paymentId' => isset($context['payment_id']) ? (string) $context['payment_id'] : null,
        ], static fn (mixed $value): bool => $value !== null && $value !== '');

        $response = $this->client->post('/payment/detail', $payload);

        if ($this->statusOf($response) !== self::STATUS_SUCCESS) {
            return StatusResponse::notFound($orderId, $response);
        }

        return new StatusResponse(
            found: true,
            status: OrderStatus::map(
                isset($response['paymentStatus']) ? (string) $response['paymentStatus'] : null,
                OrderStatus::IYZICO,
            ),
            orderId: $orderId,
            paymentId: isset($response['paymentId']) ? (string) $response['paymentId'] : null,
            amount: isset($response['paidPrice']) ? Money::fromDecimal((string) $response['paidPrice']) : null,
            installment: isset($response['installment']) ? (int) $response['installment'] : null,
            transactionTime: isset($response['createdDate']) ? (string) $response['createdDate'] : null,
            maskedCardNumber: isset($response['binNumber']) ? (string) $response['binNumber'].'******' : null,
            raw: $response,
        );
    }

    /**
     * BIN sorgusu (`/payment/bin/check`).
     */
    public function binLookup(string $bin, array $context = []): BinResponse
    {
        $response = $this->client->post('/payment/bin/check', [
            'locale' => (string) ($context['locale'] ?? 'tr'),
            'conversationId' => $bin,
            'binNumber' => $bin,
        ]);

        if ($this->statusOf($response) !== self::STATUS_SUCCESS) {
            return BinResponse::notFound($response);
        }

        return new BinResponse(
            found: true,
            bankName: isset($response['bankName']) ? (string) $response['bankName'] : null,
            brand: isset($response['cardAssociation'])
                ? strtolower(str_replace(' ', '', (string) $response['cardAssociation']))
                : null,
            type: match (strtoupper((string) ($response['cardType'] ?? ''))) {
                'CREDIT_CARD' => 'credit',
                'DEBIT_CARD' => 'debit',
                'PREPAID_CARD' => 'prepaid',
                default => null,
            },
            commercial: isset($response['commercial']) ? (int) $response['commercial'] === 1 : null,
            raw: $response,
        );
    }

    /**
     * Taksit seçeneklerini sorgular (`/payment/iyzipos/installment`).
     *
     * @return list<InstallmentOption>
     */
    public function installmentOptions(Money $amount, ?string $bin = null): array
    {
        $payload = array_filter([
            'locale' => 'tr',
            'conversationId' => $bin ?? 'installment',
            'price' => $amount->toDecimalString(),
            'binNumber' => $bin,
        ], static fn (mixed $value): bool => $value !== null && $value !== '');

        $response = $this->client->post('/payment/iyzipos/installment', $payload);

        if ($this->statusOf($response) !== self::STATUS_SUCCESS) {
            return [];
        }

        $options = [];

        foreach ($response['installmentDetails'] ?? [] as $detail) {
            if (! is_array($detail)) {
                continue;
            }

            $bankName = isset($detail['bankName']) ? (string) $detail['bankName'] : null;

            foreach ($detail['installmentPrices'] ?? [] as $price) {
                if (! is_array($price)) {
                    continue;
                }

                $count = (int) ($price['installmentNumber'] ?? 0);

                if ($count < 1) {
                    continue;
                }

                $options[] = new InstallmentOption(
                    count: $count,
                    totalPrice: isset($price['totalPrice'])
                        ? Money::fromDecimal((string) $price['totalPrice'])
                        : null,
                    monthlyPrice: isset($price['installmentPrice'])
                        ? Money::fromDecimal((string) $price['installmentPrice'])
                        : null,
                    bankName: $bankName,
                    raw: $price,
                );
            }
        }

        return $options;
    }
}
