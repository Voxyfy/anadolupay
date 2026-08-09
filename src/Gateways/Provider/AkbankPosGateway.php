<?php

declare(strict_types=1);

namespace Voxyfy\AnadoluPay\Gateways\Provider;

use Voxyfy\AnadoluPay\Contracts\SupportsCancellation;
use Voxyfy\AnadoluPay\Contracts\SupportsOrderHistory;
use Voxyfy\AnadoluPay\Contracts\SupportsPreAuthorization;
use Voxyfy\AnadoluPay\Contracts\SupportsRecurringPayments;
use Voxyfy\AnadoluPay\DTO\CapturePaymentData;
use Voxyfy\AnadoluPay\DTO\CreatePaymentData;
use Voxyfy\AnadoluPay\DTO\PaymentResponse;
use Voxyfy\AnadoluPay\DTO\RecurringPlan;
use Voxyfy\AnadoluPay\DTO\RefundPaymentData;
use Voxyfy\AnadoluPay\DTO\RefundResponse;
use Voxyfy\AnadoluPay\DTO\VerificationResponse;
use Voxyfy\AnadoluPay\Exceptions\PaymentFailedException;
use Voxyfy\AnadoluPay\Gateways\Bank\AbstractBankGateway;
use Voxyfy\AnadoluPay\Support\Bank\Currency;
use Voxyfy\AnadoluPay\Support\Money;

/**
 * Akbank Sanal POS (yeni JSON API) Driver'ı
 *
 * Not: Akbank'ın yeni API'si tekil sipariş durumu sorgusu sunmaz; bunun
 * yerine tarih aralığına göre işlem geçmişi (`txnCode 1009/1010`) döner.
 * Bu yüzden driver `SupportsStatusQuery` arayüzünü uygulamaz.
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
class AkbankPosGateway extends AbstractBankGateway implements SupportsCancellation, SupportsOrderHistory, SupportsPreAuthorization, SupportsRecurringPayments
{
    /** Başarılı işlem kodu. */
    protected const SUCCESS_CODE = 'VPS-0000';

    /** API sürümü. */
    protected const API_VERSION = '1.00';

    /** Ödeme (satış) işlem kodları. */
    protected const TXN_CODE_3D = '3000';

    protected const TXN_CODE_NON_SECURE = '1000';

    /** Ön provizyon işlem kodları. */
    protected const TXN_CODE_3D_PRE_AUTH = '3004';

    protected const TXN_CODE_NON_SECURE_PRE_AUTH = '1004';

    /** Akbank tekrar frekansı kodları. */
    protected const RECURRING_FREQUENCIES = [
        RecurringPlan::FREQUENCY_DAY => 'D',
        RecurringPlan::FREQUENCY_WEEK => 'W',
        RecurringPlan::FREQUENCY_MONTH => 'M',
        RecurringPlan::FREQUENCY_YEAR => 'Y',
    ];

    /** Provizyon kapama işlem kodu. */
    protected const TXN_CODE_CAPTURE = '1005';

    /**
     * @return array<string, mixed>
     */
    protected function build3dFormFields(CreatePaymentData $data): array
    {
        $this->config->require(['merchantId', 'terminalId', 'secretKey']);

        $inputs = [
            'paymentModel' => $this->paymentModelCode($data->paymentModel),
            'txnCode' => $data->preAuthorization ? self::TXN_CODE_3D_PRE_AUTH : self::TXN_CODE_3D,
            'merchantSafeId' => $this->config->merchantId,
            'terminalSafeId' => $this->config->terminalId,
            'orderId' => $data->orderId,
            'lang' => strtoupper($data->lang) === 'EN' ? 'EN' : 'TR',
            'amount' => $this->formatAmount($data->money()),
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
            // İade ve iptal sipariş numarasıyla çalışır; `paymentId` olarak
            // banka referansı (rrn) verilirse sonraki işlem yapılamaz.
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
        $approved = $this->pick($provision, ['responseCode']) === self::SUCCESS_CODE;

        return new VerificationResponse(
            success: $approved,
            paymentId: $this->extractOrderId($payload),
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
            'txnCode' => $data->preAuthorization ? self::TXN_CODE_NON_SECURE_PRE_AUTH : self::TXN_CODE_NON_SECURE,
            'requestDateTime' => $this->requestDateTime(),
            'randomNumber' => $this->randomString(128),
            'card' => [
                'cardNumber' => $card->number,
                'cvv2' => $card->cvv,
                'expireDate' => $card->expiry('my'),
            ],
            'order' => ['orderId' => $data->orderId],
            'transaction' => [
                'amount' => $this->formatAmount($data->money()),
                'currencyCode' => (int) Currency::numeric($data->currency),
                'motoInd' => 0,
                'installCount' => $data->installments(),
            ],
            'customer' => ['ipAddress' => $data->clientIp()],
        ] + $this->recurringRequest($data));

        $approved = $this->pick($response, ['responseCode']) === self::SUCCESS_CODE;

        return new PaymentResponse(
            success: $approved,
            paymentId: $data->orderId,
            errorMessage: $approved ? null : $this->pick($response, ['hostMessage', 'responseMessage']),
            raw: $response,
            errorCode: $approved ? null : $this->pick($response, ['responseCode']),
        );
    }

    /**
     * Akbank tekrar frekansları.
     *
     * @return list<string>
     */
    public function supportedRecurringFrequencies(): array
    {
        return array_keys(self::RECURRING_FREQUENCIES);
    }

    /**
     * Tekrarlayan ödeme bloğu.
     *
     * @return array<string, mixed>
     */
    protected function recurringRequest(CreatePaymentData $data): array
    {
        $plan = $this->recurringPlan($data);

        if ($plan === null) {
            return [];
        }

        return [
            'recurring' => [
                'frequencyInterval' => $plan->interval,
                'frequencyCycle' => $plan->frequencyCode(self::RECURRING_FREQUENCIES),
                'numberOfPayments' => $plan->paymentCount,
            ],
        ];
    }

    /**
     * Tam veya kısmi iade (txnCode 1002).
     */
    protected function performRefund(RefundPaymentData $data): RefundResponse
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
            'order' => ['orderId' => $this->reversalOrderId($data)],
            'transaction' => [
                'amount' => $this->formatAmount($this->resolveRefundAmount($data)),
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
            'order' => ['orderId' => $this->reversalOrderId($data)],
        ]));
    }

    /**
     * İade edilecek tutar; verilmemişse siparişten çözülür.
     *
     * Akbank tutarsız iade kabul etmez — alan `0.00` gider ve banka
     * `Hatalı Tutar` döndürür. Tutar verilmediğinde işlem geçmişinden
     * (satışlar eksi önceki iadeler) kalan tutar hesaplanır.
     */
    protected function resolveRefundAmount(RefundPaymentData $data): Money
    {
        return $data->money() ?? $this->remainingAmount($this->reversalOrderId($data), $data->currency);
    }

    /**
     * Sipariş üzerinde işlem yapılabilecek kalan tutar.
     *
     * Satış ve ön provizyon kayıtları eklenir, iade ve iptaller düşülür.
     *
     * @throws PaymentFailedException kalan tutar hesaplanamazsa
     */
    protected function remainingAmount(string $orderId, string $currency): Money
    {
        $history = $this->orderHistory($orderId);
        $kurus = 0;

        foreach ($history['txnDetailList'] ?? [] as $txn) {
            if (! is_array($txn) || ($txn['responseCode'] ?? null) !== self::SUCCESS_CODE) {
                continue;
            }

            $tutar = Money::fromDecimal((string) ($txn['amount'] ?? 0), $currency)->minorUnits;

            $kurus += match ((string) ($txn['txnCode'] ?? '')) {
                self::TXN_CODE_NON_SECURE, self::TXN_CODE_3D,
                self::TXN_CODE_NON_SECURE_PRE_AUTH, self::TXN_CODE_3D_PRE_AUTH => $tutar,
                '1002', '1003' => -$tutar,
                default => 0,
            };
        }

        if ($kurus <= 0) {
            throw new PaymentFailedException(
                message: 'Akbank siparişinde işlem yapılabilecek tutar bulunamadı; tutarı açıkça verin.',
                context: ['order_id' => $orderId, 'response' => $history],
            );
        }

        return Money::fromMinorUnits($kurus, $currency);
    }

    /**
     * İade ve iptalin dayandığı sipariş numarası.
     *
     * Akbank bu işlemleri banka referansıyla (rrn) değil sipariş numarasıyla
     * eşler; yanlışı gönderildiğinde `VPS-1007 Orjinal İşlem bulunamadı`
     * döner. Sürücü ödeme yanıtında zaten sipariş numarasını `paymentId`
     * olarak verir; eski kayıtlar için `metadata['order_id']` da okunur.
     */
    protected function reversalOrderId(RefundPaymentData $data): string
    {
        return (string) ($data->meta('order_id') ?? $data->paymentId);
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
     * Akbank tutarları iki ondalıklı gösterimle bekler.
     */
    protected function formatAmount(Money $money): string
    {
        return $money->toDecimalString();
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

    /**
     * Ön provizyonu kapatır (`txnCode 1005`).
     */
    public function capture(CapturePaymentData $data): PaymentResponse
    {
        $response = $this->postJson([
            'terminal' => [
                'merchantSafeId' => $this->config->merchantId,
                'terminalSafeId' => $this->config->terminalId,
            ],
            'version' => self::API_VERSION,
            'txnCode' => self::TXN_CODE_CAPTURE,
            'requestDateTime' => $this->requestDateTime(),
            'randomNumber' => $this->randomString(128),
            'order' => ['orderId' => $data->orderId],
            'transaction' => [
                // Tutar verilmezse ön provizyonun tamamı kapatılır.
                'amount' => $this->formatAmount($data->money() ?? $this->remainingAmount($data->orderId, $data->currency)),
                'currencyCode' => (int) Currency::numeric($data->currency),
            ],
            'customer' => ['ipAddress' => $data->clientIp()],
        ]);

        $approved = $this->pick($response, ['responseCode']) === self::SUCCESS_CODE;

        return new PaymentResponse(
            success: $approved,
            paymentId: $data->orderId,
            errorMessage: $approved ? null : $this->pick($response, ['hostMessage', 'responseMessage']),
            raw: $response,
            errorCode: $approved ? null : $this->pick($response, ['responseCode']),
        );
    }

    /**
     * Siparişin hareket dökümü (`txnCode 1010`).
     *
     * Akbank tekil durum sorgusu sunmadığı için siparişin son durumu da
     * bu dökümden okunur.
     *
     * @return array<string, mixed>
     */
    public function orderHistory(string $orderId, array $context = []): array
    {
        return $this->postJson([
            'terminal' => [
                'merchantSafeId' => $this->config->merchantId,
                'terminalSafeId' => $this->config->terminalId,
            ],
            'version' => self::API_VERSION,
            'txnCode' => '1010',
            'requestDateTime' => $this->requestDateTime(),
            'randomNumber' => $this->randomString(128),
            'order' => ['orderId' => $orderId],
        ]);
    }
}
