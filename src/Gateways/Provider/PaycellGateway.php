<?php

declare(strict_types=1);

namespace Voxyfy\AnadoluPay\Gateways\Provider;

use Voxyfy\AnadoluPay\Contracts\SupportsCancellation;
use Voxyfy\AnadoluPay\Contracts\SupportsPreAuthorization;
use Voxyfy\AnadoluPay\Contracts\SupportsStatusQuery;
use Voxyfy\AnadoluPay\DTO\CapturePaymentData;
use Voxyfy\AnadoluPay\DTO\CreatePaymentData;
use Voxyfy\AnadoluPay\DTO\PaymentResponse;
use Voxyfy\AnadoluPay\DTO\RefundPaymentData;
use Voxyfy\AnadoluPay\DTO\RefundResponse;
use Voxyfy\AnadoluPay\DTO\StatusResponse;
use Voxyfy\AnadoluPay\DTO\VerificationResponse;
use Voxyfy\AnadoluPay\DTO\VerifyPaymentData;
use Voxyfy\AnadoluPay\Exceptions\InvalidSignatureException;
use Voxyfy\AnadoluPay\Exceptions\PaymentFailedException;
use Voxyfy\AnadoluPay\Gateways\Bank\AbstractBankGateway;
use Voxyfy\AnadoluPay\Support\Money;

/**
 * Paycell (Turkcell) Driver'ı
 *
 * Protokol özeti:
 *   - Kart bilgisi ödeme ucuna hiç gitmez. Önce ayrı bir uçta
 *     (`getCardTokenSecure`) karttan bir `cardToken` alınır, ödeme o token
 *     ile yapılır. Bu yüzden iki ayrı taban adres vardır: token ucu ve
 *     provizyon ucu.
 *   - İmza iki aşamalıdır ve **tamamı büyük harfe çevrilerek** hesaplanır:
 *
 *       securityData = base64( sha256( UPPER( applicationPwd + applicationName ) ) )
 *       hashData     = base64( sha256( UPPER( applicationName + transactionId
 *                                             + transactionDateTime
 *                                             + [responseCode] + [cardToken]
 *                                             + secureCode + securityData ) ) )
 *
 *     İstekte `responseCode` ve `cardToken` yoktur; yanıt imzasında ikisi de
 *     araya girer. Büyük harfe çevirme adımı atlanırsa imza hiçbir zaman
 *     tutmaz — bu, Paycell'in en sık düşülen tuzağıdır.
 *   - `transactionId` 20 hane olmalıdır; kısa gönderildiğinde sağlayıcı
 *     `80003 transactionId bos ya da formati dogru degil` döndürür (ölçüldü).
 *   - `transactionDateTime` biçimi `yyyyMMddHHmmssSSS`'tir (17 hane).
 *   - Tutarlar kuruş cinsindendir, başarı kodu `0`dır.
 *   - Paycell abone tabanlı çalıştığı için isteklerde bir `msisdn` alanı
 *     bulunur; kart ödemelerinde üye işyerine tanımlı numara kullanılır.
 */
class PaycellGateway extends AbstractBankGateway implements SupportsCancellation, SupportsPreAuthorization, SupportsStatusQuery
{
    /** Başarılı işlem kodu. */
    protected const SUCCESS_CODE = '0';

    /** 3D oturumu açarken kullanılan sabitler. */
    protected const TARGET = 'MERCHANT';

    protected const TRANSACTION_TYPE = 'AUTH';

    /**
     * Kart ödemesi iki adımdır: önce kart token'a çevrilir, sonra ödeme
     * yapılır. 3D'de araya oturum açma ve doğrulama sayfası girer.
     */
    protected function performCreatePayment(CreatePaymentData $data): PaymentResponse
    {
        if ($data->paymentModel === CreatePaymentData::MODEL_NON_SECURE) {
            return $this->nonSecurePayment($data);
        }

        $cardToken = $this->createCardToken($data);
        $sessionId = $this->openThreeDSession($data, $cardToken);

        return new PaymentResponse(
            success: true,
            paymentId: $data->orderId,
            raw: ['card_token' => $cardToken, 'three_d_session_id' => $sessionId],
            formAction: $this->config->endpoint('gateway_3d'),
            formFields: [
                'threeDSessionId' => $sessionId,
                // Paycell dönüşte yalnızca bu adrese yönlendirir; oturum
                // kimliğini gövdede taşımaz. Bu yüzden `threeDSessionId` ve
                // `msisdn` sorgu dizgisine konur, aksi hâlde `verify()`
                // hangi işlemi sorgulayacağını bilemez.
                'callbackurl' => $this->callbackUrl($data, $sessionId),
                'isPost3DResult' => 'true',
            ],
        );
    }

    /**
     * 3D dönüş adresi.
     *
     * Oturum kimliği ve abone numarası sorgu dizgisinde taşınır.
     */
    protected function callbackUrl(CreatePaymentData $data, string $sessionId): string
    {
        $url = $this->successUrl($data);

        return $url.(str_contains($url, '?') ? '&' : '?').http_build_query([
            'threeDSessionId' => $sessionId,
            'msisdn' => $this->msisdn($data),
        ]);
    }

    /**
     * Kart bilgisini token'a çevirir.
     *
     * Bu istek provizyon ucuna değil, ayrı bir ödeme yönetimi ucuna gider.
     * Yanıt imzası doğrulanır: sağlayıcının ürettiği `hashData`, aynı
     * formülle yeniden hesaplanıp karşılaştırılır.
     *
     * @throws PaymentFailedException Token alınamazsa
     * @throws InvalidSignatureException Yanıt imzası tutmazsa
     */
    protected function createCardToken(CreatePaymentData $data): string
    {
        $this->config->require(['username', 'password', 'secretKey']);

        $card = $this->requireCard($data);
        $transactionId = $this->transactionId();
        $dateTime = $this->transactionDateTime();

        $response = $this->client->postJson(
            url: $this->config->endpoint('token_api'),
            data: [
                'header' => [
                    'applicationName' => $this->config->username,
                    'transactionId' => $transactionId,
                    'transactionDateTime' => $dateTime,
                ],
                'hashData' => $this->requestHash($transactionId, $dateTime),
                'creditCardNo' => $card->number,
                'expireDateMonth' => $card->expiry('m'),
                'expireDateYear' => $card->expiry('Y'),
                'cvcNo' => $card->cvv,
            ],
        );

        $token = $this->pick($response, ['cardToken']);
        $header = is_array($response['header'] ?? null) ? $response['header'] : [];

        if ($token === null || $token === '') {
            throw new PaymentFailedException(
                message: $this->pick($header, ['responseDescription']) ?? 'Paycell kart token isteği başarısız oldu.',
                context: ['response' => $response],
            );
        }

        if ($this->config->verifyHash && ! $this->checkTokenHash($response, $token, $transactionId)) {
            throw new InvalidSignatureException($this->config->bank, [
                'reason' => 'card_token_hash_mismatch',
                'transaction_id' => $transactionId,
            ]);
        }

        return $token;
    }

    /**
     * Token yanıtının imzasını doğrular.
     *
     * `hashData` yanıtın **kökünde** gelir, `header` içinde değil; imzalanan
     * alanlar ise `header` altındadır. Ayrıca sağlayıcı yanıtta kendi
     * `transactionId` değerini döndürür — imza istekte gönderilenle değil,
     * yanıtta gelenle hesaplanır (test ortamında ölçüldü).
     *
     * @param  array<string, mixed>  $response
     */
    protected function checkTokenHash(array $response, string $cardToken, string $transactionId): bool
    {
        $incoming = $this->pick($response, ['hashData']);
        $header = is_array($response['header'] ?? null) ? $response['header'] : [];

        if ($incoming === null) {
            return false;
        }

        $expected = $this->responseHash(
            transactionId: $this->pick($header, ['transactionId']) ?? $transactionId,
            dateTime: $this->pick($header, ['responseDateTime']) ?? '',
            responseCode: $this->pick($header, ['responseCode']) ?? '',
            cardToken: $cardToken,
        );

        return hash_equals($expected, $incoming);
    }

    /**
     * 3D doğrulama oturumu açar ve `threeDSessionId` döndürür.
     *
     * @throws PaymentFailedException Oturum açılamazsa
     */
    protected function openThreeDSession(CreatePaymentData $data, string $cardToken): string
    {
        $this->config->require(['merchantId']);

        $request = $this->accountData() + [
            'merchantCode' => $this->config->merchantId,
            'msisdn' => $this->msisdn($data),
            'cardToken' => $cardToken,
            'amount' => $this->formatAmount($data->money()),
            'target' => self::TARGET,
            'transactionType' => self::TRANSACTION_TYPE,
        ];

        if (($installment = $data->installments()) > 1) {
            $request['installmentCount'] = $installment;
        }

        $response = $this->post('getThreeDSession', $request);
        $sessionId = $this->pick($response, ['threeDSessionId']);

        if ($sessionId === null || $sessionId === '') {
            throw new PaymentFailedException(
                message: $this->responseDescription($response) ?? 'Paycell 3D oturumu açılamadı.',
                context: ['response' => $response],
            );
        }

        return $sessionId;
    }

    /**
     * 3D dönüşü: önce oturum sonucu sorgulanır, doğrulama başarılıysa
     * ödeme aynı oturum kimliğiyle finansallaştırılır.
     */
    protected function performVerify(VerifyPaymentData $data): VerificationResponse
    {
        $payload = $data->payload;
        $sessionId = $this->pick($payload, ['threeDSessionId']);

        if ($sessionId === null || $sessionId === '') {
            return new VerificationResponse(
                success: false,
                paymentId: $this->extractOrderId($payload),
                status: 'failed',
                raw: ['callback' => $payload, 'error_message' => 'Paycell dönüşünde threeDSessionId yok.'],
            );
        }

        $result = $this->post('getThreeDSessionResult', $this->accountData() + [
            'msisdn' => $this->msisdn(null, $data->order('msisdn')),
            'threeDSessionId' => $sessionId,
        ]);

        if (! $this->is3dAuthSuccess($result)) {
            return new VerificationResponse(
                success: false,
                paymentId: $this->extractOrderId($payload),
                status: 'failed',
                raw: [
                    'callback' => $payload,
                    'session' => $result,
                    'error_message' => $this->pick($result, ['mdErrorMessage']) ?? $this->responseDescription($result),
                ],
            );
        }

        $provision = $this->post('provision', $this->accountData() + [
            'msisdn' => $this->msisdn(null, $data->order('msisdn')),
            'merchantCode' => $this->config->merchantId,
            'referenceNumber' => $this->referenceNumber($data->order('order_id') ?? $this->extractOrderId($payload)),
            'amount' => (string) ($data->order('amount') ?? $this->pick($payload, ['amount'], '') ?? ''),
            'currency' => (string) ($data->order('currency') ?? 'TRY'),
            'paymentType' => 'SALE',
            'threeDSessionId' => $sessionId,
        ]);

        return $this->mapProvisionResponse($payload, $provision);
    }

    /**
     * 3D'siz doğrudan ödeme: token alınır ve hemen finansallaştırılır.
     */
    protected function nonSecurePayment(CreatePaymentData $data): PaymentResponse
    {
        $cardToken = $this->createCardToken($data);

        $request = $this->accountData() + [
            'msisdn' => $this->msisdn($data),
            'merchantCode' => $this->config->merchantId,
            'referenceNumber' => $this->referenceNumber($data->orderId),
            'cardToken' => $cardToken,
            'amount' => $this->formatAmount($data->money()),
            'currency' => $data->currency,
            'paymentType' => $data->preAuthorization ? 'PREAUTH' : 'SALE',
            'installmentCount' => $data->installments() > 1 ? $data->installments() : 0,
        ];

        $response = $this->post('provision', $request);
        $approved = $this->approved($response);

        return new PaymentResponse(
            success: $approved,
            paymentId: $this->pick($response, ['orderId']) ?? $data->orderId,
            errorMessage: $approved ? null : $this->responseDescription($response),
            raw: $response,
            errorCode: $approved ? null : $this->responseCode($response),
        );
    }

    /**
     * Tam veya kısmi iade.
     */
    protected function performRefund(RefundPaymentData $data): RefundResponse
    {
        $request = $this->accountData() + [
            'msisdn' => $this->msisdn(),
            'merchantCode' => $this->config->merchantId,
            'referenceNumber' => $this->referenceNumber(),
            'originalReferenceNumber' => $data->paymentId,
        ];

        if (($amount = $data->money()) !== null) {
            $request['amount'] = $this->formatAmount($amount);
        }

        return $this->mapReversal($this->post('refund', $request));
    }

    /**
     * Gün içi işlem iptali.
     *
     * Paycell iptali yalnızca aynı gün kabul eder; sonraki günlerde iade
     * kullanılmalıdır.
     */
    public function cancel(RefundPaymentData $data): RefundResponse
    {
        return $this->mapReversal($this->post('reverse', $this->accountData() + [
            'msisdn' => $this->msisdn(),
            'merchantCode' => $this->config->merchantId,
            'referenceNumber' => $this->referenceNumber(),
            'originalReferenceNumber' => $data->paymentId,
        ]));
    }

    /**
     * Ön provizyonu kapatır.
     */
    public function capture(CapturePaymentData $data): PaymentResponse
    {
        $request = $this->accountData() + [
            'msisdn' => $this->msisdn(),
            'merchantCode' => $this->config->merchantId,
            'referenceNumber' => $this->referenceNumber(),
            'originalReferenceNumber' => $data->orderId,
            'paymentType' => 'POSTAUTH',
            'currency' => $data->currency,
        ];

        if (($amount = $data->money()) !== null) {
            $request['amount'] = $this->formatAmount($amount);
        }

        $response = $this->post('provision', $request);
        $approved = $this->approved($response);

        return new PaymentResponse(
            success: $approved,
            paymentId: $this->pick($response, ['orderId']) ?? $data->orderId,
            errorMessage: $approved ? null : $this->responseDescription($response),
            raw: $response,
            errorCode: $approved ? null : $this->responseCode($response),
        );
    }

    /**
     * İşlem durumunu sorgular.
     */
    public function status(string $orderId, array $context = []): StatusResponse
    {
        $response = $this->post('inquire', $this->accountData() + [
            'msisdn' => $this->msisdn(),
            'merchantCode' => $this->config->merchantId,
            'referenceNumber' => $this->referenceNumber(),
            'originalReferenceNumber' => $orderId,
        ]);

        if (! $this->approved($response)) {
            return StatusResponse::notFound($orderId, $response);
        }

        $refunded = $this->pick($response, ['refundedAmount']);
        $refundedMoney = $refunded !== null && (int) $refunded > 0
            ? Money::fromMinorUnits((int) $refunded, 'TRY')
            : null;

        return new StatusResponse(
            found: true,
            status: match (true) {
                $this->pick($response, ['reversedAmount']) !== null
                    && (int) $this->pick($response, ['reversedAmount'], '0') > 0 => StatusResponse::STATUS_CANCELLED,
                $refundedMoney !== null => StatusResponse::STATUS_REFUNDED,
                default => StatusResponse::STATUS_PAID,
            },
            orderId: $orderId,
            paymentId: $this->pick($response, ['orderId']),
            amount: ($amount = $this->pick($response, ['amount'])) !== null
                ? Money::fromMinorUnits((int) $amount, 'TRY')
                : null,
            refundedAmount: $refundedMoney,
            transactionTime: $this->pick($response, ['provisionDate', 'responseDateTime']),
            errorMessage: null,
            raw: $response,
        );
    }

    /**
     * @param  array<string, mixed>  $response
     */
    protected function mapReversal(array $response): RefundResponse
    {
        $approved = $this->approved($response);

        return new RefundResponse(
            success: $approved,
            refundId: $this->pick($response, ['orderId', 'approvalCode']),
            errorMessage: $approved ? null : $this->responseDescription($response),
            raw: $response,
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $provision
     */
    protected function mapProvisionResponse(array $payload, array $provision): VerificationResponse
    {
        $approved = $this->approved($provision);

        return new VerificationResponse(
            success: $approved,
            paymentId: $this->pick($provision, ['orderId']) ?? $this->extractOrderId($payload),
            status: $approved ? 'success' : 'failed',
            raw: [
                'callback' => $payload,
                'provision' => $provision,
                'error_message' => $approved ? null : $this->responseDescription($provision),
            ],
        );
    }

    /**
     * İstek imzası — yanıt alanları yoktur.
     */
    protected function requestHash(string $transactionId, string $dateTime): string
    {
        return $this->hashData($transactionId, $dateTime);
    }

    /**
     * Yanıt imzası — araya `responseCode` ve `cardToken` girer.
     */
    protected function responseHash(string $transactionId, string $dateTime, string $responseCode, string $cardToken): string
    {
        return $this->hashData($transactionId, $dateTime, $responseCode, $cardToken);
    }

    /**
     * İki aşamalı imza. Her iki aşamada da girdi büyük harfe çevrilir.
     */
    protected function hashData(string $transactionId, string $dateTime, string $responseCode = '', string $cardToken = ''): string
    {
        $securityData = $this->hash($this->config->password.$this->config->username);

        return $this->hash(
            $this->config->username
            .$transactionId
            .$dateTime
            .$responseCode
            .$cardToken
            .$this->config->secretKey
            .$securityData
        );
    }

    protected function hash(string $value): string
    {
        return base64_encode(hash('sha256', strtoupper($value), true));
    }

    /**
     * Her isteğe eklenen kimlik ve imza başlığı.
     *
     * @return array<string, mixed>
     */
    protected function accountData(): array
    {
        $this->config->require(['username', 'password', 'secretKey']);

        $transactionId = $this->transactionId();
        $dateTime = $this->transactionDateTime();

        return [
            'requestHeader' => [
                'applicationName' => $this->config->username,
                'applicationPwd' => $this->config->password,
                'clientIPAddress' => $this->config->extra('client_ip', '127.0.0.1'),
                'transactionId' => $transactionId,
                'transactionDateTime' => $dateTime,
            ],
            'hashData' => $this->requestHash($transactionId, $dateTime),
        ];
    }

    /**
     * @param  array<string, mixed>  $request
     * @return array<string, mixed>
     */
    protected function post(string $operation, array $request): array
    {
        return $this->client->postJson(
            url: rtrim($this->config->endpoint('payment_api'), '/').'/'.$operation.'/',
            data: $request,
        );
    }

    /**
     * Paycell abone numarası ister; kart ödemelerinde üye işyerine tanımlı
     * numara kullanılır.
     */
    protected function msisdn(?CreatePaymentData $data = null, mixed $override = null): string
    {
        if (is_string($override) && $override !== '') {
            return $override;
        }

        $fromCustomer = $data?->customer['msisdn'] ?? $data?->customer['phone'] ?? null;

        if (is_string($fromCustomer) && $fromCustomer !== '') {
            return $fromCustomer;
        }

        return (string) $this->config->extra('msisdn', '');
    }

    /**
     * Referans numarası 20 hanedir; sipariş numarası kısaysa soldan sıfırla
     * doldurulur, uzunsa sessizce kesmek yerine hata verilir.
     *
     * @throws PaymentFailedException Sipariş numarası 20 haneye sığmıyorsa
     */
    protected function referenceNumber(?string $orderId = null): string
    {
        if ($orderId === null || $orderId === '') {
            return $this->transactionId();
        }

        $digits = preg_replace('/\D/', '', $orderId) ?? '';

        if (strlen($digits) > 20) {
            throw new PaymentFailedException(
                message: "Paycell referans numarası en fazla 20 hane olabilir; '{$orderId}' bu sınırı aşıyor.",
                context: ['order_id' => $orderId],
            );
        }

        return str_pad($digits === '' ? '0' : $digits, 20, '0', STR_PAD_LEFT);
    }

    /**
     * 20 haneli benzersiz işlem numarası.
     *
     * Rakamlar tek tek üretilir: 20 haneli bir sayıyı tam sayı olarak
     * tutmaya çalışmak 64 bitte taşar ve bilimsel gösterime düşer.
     */
    protected function transactionId(): string
    {
        $id = '';

        while (strlen($id) < 20) {
            $id .= str_pad((string) random_int(0, 999999999), 9, '0', STR_PAD_LEFT);
        }

        return substr($id, 0, 20);
    }

    /**
     * `yyyyMMddHHmmssSSS` — 17 hane.
     */
    protected function transactionDateTime(): string
    {
        return date('YmdHis').str_pad((string) random_int(0, 999), 3, '0', STR_PAD_LEFT);
    }

    /**
     * Paycell tutarları kuruş cinsinden tam sayı olarak bekler.
     */
    protected function formatAmount(Money $money): string
    {
        return $money->toMinorUnitsString();
    }

    /**
     * @param  array<string, mixed>  $response
     */
    protected function approved(array $response): bool
    {
        return $this->responseCode($response) === self::SUCCESS_CODE;
    }

    /**
     * Yanıt kodu isteğe göre `header` veya `responseHeader` altında gelir.
     *
     * @param  array<string, mixed>  $response
     */
    protected function responseCode(array $response): ?string
    {
        return $this->pick($this->responseHeader($response), ['responseCode']);
    }

    /**
     * @param  array<string, mixed>  $response
     */
    protected function responseDescription(array $response): ?string
    {
        return $this->pick($this->responseHeader($response), ['responseDescription']);
    }

    /**
     * @param  array<string, mixed>  $response
     * @return array<string, mixed>
     */
    protected function responseHeader(array $response): array
    {
        foreach (['responseHeader', 'header'] as $key) {
            if (is_array($response[$key] ?? null)) {
                /** @var array<string, mixed> */
                return $response[$key];
            }
        }

        return $response;
    }

    /**
     * 3D formunun POST edileceği adres.
     */
    protected function build3dFormFields(CreatePaymentData $data): array
    {
        // Form alanları performCreatePayment içinde oturumdan üretilir.
        return [];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function checkCallbackHash(array $payload): bool
    {
        // Paycell 3D dönüşünde imza taşımaz; doğrulama oturum sorgusuyla
        // yapılır (bkz. performVerify).
        return true;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function is3dAuthSuccess(array $payload): bool
    {
        return in_array($this->pick($payload, ['mdStatus']), ['1', '2', '3', '4'], true)
            && $this->pick($payload, ['currentStep']) !== 'PAYMENT_FAILED';
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    protected function provision(array $payload): array
    {
        // verify() kendi akışını yürüttüğü için bu yol kullanılmaz.
        return [];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function mapCallbackResponse(array $payload): VerificationResponse
    {
        return new VerificationResponse(
            success: false,
            paymentId: $this->extractOrderId($payload),
            status: 'failed',
            raw: $payload,
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function extractOrderId(array $payload): ?string
    {
        return $this->pick($payload, ['referenceNumber', 'orderId', 'threeDSessionId']);
    }
}
