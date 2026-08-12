<?php

declare(strict_types=1);

namespace Voxyfy\AnadoluPay\Gateways\Provider;

use Voxyfy\AnadoluPay\Contracts\SupportsCancellation;
use Voxyfy\AnadoluPay\Contracts\SupportsPreAuthorization;
use Voxyfy\AnadoluPay\Contracts\SupportsStatusQuery;
use Voxyfy\AnadoluPay\DTO\CapturePaymentData;
use Voxyfy\AnadoluPay\DTO\CardData;
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
 * Tami Ödeme Kuruluşu Driver'ı (dev.tami.com.tr)
 *
 * Tami, düz JSON tabanlı, modern bir sanal POS/PSP API'sidir (Craftgate'e
 * yakın bir seviyede). Auth + 3D başlatma tek bir uca gider
 * (`/payment/auth`); 3D doğrulaması tamamlandığında banka `callbackUrl`'e
 * POST eder, ardından **ayrı bir `/payment/complete-3ds` isteği** parayı
 * fiilen çeker. Bu yüzden `performCreatePayment()`/`performVerify()`
 * (KuveytPos/Craftgate gibi) tamamen override edilir.
 *
 * Kimlik doğrulama iki katmanlıdır:
 *   1. `PG-Auth-Token` header'ı: `merchantNumber:terminalNumber:hash`,
 *      hash = base64(sha256(merchantNumber+terminalNumber+secretKey)).
 *   2. Gövdedeki `securityHash` alanı: HS512 ile imzalanmış bir JWT
 *      (JWS compact serileştirme) — header `{alg:HS512,typ:JWT,kid}`,
 *      payload gövdenin (securityHash hariç) JSON'u, anahtar üye işyeri
 *      panelinden alınan JWK `k` değeri.
 *
 * **Bilinen sınırlar — henüz gerçek bir sandbox'a karşı doğrulanmadı:**
 *   - `securityHash` için JWT/JWS yorumu "Security Hash Hesaplama"
 *     sayfasındaki somut kod örneğine (JWSObject/MACSigner) dayanır;
 *     ama diğer sayfalardaki örnek `securityHash` değerleri nokta
 *     içermiyor (düz bir HMAC digest'i gibi görünüyor, JWT gibi değil).
 *     Dokümantasyon bu noktada kendi içinde çelişkili — bkz. `securityHash()`.
 *   - Ön provizyon kapama ucu (`/payment/post-auth`) doğrulanmadı;
 *     `/payment/pre-auth` ile aynı adlandırma kalıbından tahmin edildi.
 *   - 3D dönüş callback'inin imzası (`hashedData`, HMAC-SHA256) Tami'nin
 *     kendi dokümantasyonunda formülsüz bırakılmıştı; alan sırası ve
 *     anahtarı bağımsız bir kaynaktan doğrulandı — bkz. `checkCallbackHash()`.
 *     Tami'nin resmî bir onayı değil, sandbox'a karşı test edilmedi.
 *   - İptal (`cancel`) ve iade (`performRefund`) dokümantasyonda **aynı
 *     uca** (`/payment/reverse`) gidiyor; ikisi arasında protokol
 *     seviyesinde bir fark bulunamadı.
 */
class TamiGateway extends AbstractBankGateway implements SupportsCancellation, SupportsPreAuthorization, SupportsStatusQuery
{
    /**
     * 3D başlatma isteğinin akışı: banka hazır bir HTML sayfası döner.
     */
    protected function performCreatePayment(CreatePaymentData $data): PaymentResponse
    {
        if ($data->paymentModel === CreatePaymentData::MODEL_NON_SECURE) {
            return $this->nonSecurePayment($data);
        }

        $response = $this->post('/payment/auth', $this->paymentRequest($data, with3d: true));

        if (($response['success'] ?? false) !== true) {
            return new PaymentResponse(
                success: false,
                paymentId: $data->orderId,
                errorMessage: $this->errorMessage($response) ?? 'Tami 3D başlatma isteği başarısız oldu.',
                raw: $response,
                errorCode: $this->pick($response, ['errorCode']),
            );
        }

        $html = $this->pick($response, ['threeDSHtmlContent']);

        if ($html === null) {
            throw new PaymentFailedException(
                message: 'Tami 3D başlatma isteği HTML içerik döndürmedi.',
                context: ['response' => $response],
            );
        }

        return new PaymentResponse(
            success: true,
            paymentId: (string) ($response['orderId'] ?? $data->orderId),
            raw: $response,
            // threeDSHtmlContent base64 kodludur.
            htmlContent: base64_decode($html, true) ?: $html,
        );
    }

    /**
     * 3D'siz doğrudan provizyon.
     */
    protected function nonSecurePayment(CreatePaymentData $data): PaymentResponse
    {
        $response = $this->post('/payment/auth', $this->paymentRequest($data, with3d: false));

        return $this->mapPaymentResponse($response, $data->orderId);
    }

    /**
     * Ortak istek gövdesi (satış / 3D başlatma / ön otorizasyon).
     *
     * @return array<string, mixed>
     */
    protected function paymentRequest(CreatePaymentData $data, bool $with3d): array
    {
        $this->config->require(['merchantId', 'terminalId', 'secretKey', 'username', 'password']);

        $card = $this->requireCard($data);

        $request = [
            'orderId' => $data->orderId,
            'amount' => $data->money()->toDecimalString(),
            'currency' => strtoupper($data->currency),
            'installmentCount' => $data->installments(),
            'paymentGroup' => (string) $this->config->extra('payment_group', 'PRODUCT'),
            'card' => $this->cardFields($card),
            'buyer' => $this->buyer($data),
        ];

        if ($with3d) {
            $request['callbackUrl'] = $this->successUrl($data);
        }

        return $request;
    }

    /**
     * @return array<string, mixed>
     */
    protected function cardFields(CardData $card): array
    {
        return [
            'number' => $card->number,
            'holderName' => $card->holderName ?? '',
            'expireMonth' => (int) $card->expireMonth,
            'expireYear' => (int) $card->expireYearLong(),
            'cvv' => $card->cvv,
        ];
    }

    /**
     * Alıcı bilgisi. Tami `name`/`surName`'i ayrı alanlar olarak ister;
     * `customer['first_name']`/`['last_name']` verilmemişse tek bir
     * `name` alanı ilk boşluktan bölünür.
     *
     * @return array<string, mixed>
     */
    protected function buyer(CreatePaymentData $data): array
    {
        $customer = $data->customer;
        $fullName = trim((string) ($customer['name'] ?? 'Musteri'));
        $parts = explode(' ', $fullName, 2);

        return array_filter([
            'buyerId' => (string) ($customer['id'] ?? $data->orderId),
            'name' => (string) ($customer['first_name'] ?? $parts[0]),
            'surName' => (string) ($customer['last_name'] ?? $parts[1] ?? '-'),
            'emailAddress' => (string) ($customer['email'] ?? ''),
            'phoneNumber' => (string) ($customer['phone'] ?? $customer['gsm_number'] ?? ''),
            'ipAddress' => $data->clientIp(),
            'identityNumber' => $customer['identity_number'] ?? null,
            'city' => $customer['city'] ?? null,
            'country' => $customer['country'] ?? null,
            'zipCode' => $customer['zip_code'] ?? null,
        ], static fn (mixed $value): bool => $value !== null && $value !== '');
    }

    /**
     * 3D dönüşünü doğrular ve `complete-3ds` ile parayı çeker.
     *
     * Standart `verify()` akışı burada uymuyor: provizyon isteği (`provision()`)
     * yalnızca `orderId` alır, banka dönüşünün diğer alanlarını kullanmaz.
     */
    protected function performVerify(VerifyPaymentData $data): VerificationResponse
    {
        $payload = $data->payload;

        if ($this->config->verifyHash && ! $this->checkCallbackHash($payload)) {
            throw new InvalidSignatureException($this->config->bank, [
                'reason' => 'hash_mismatch',
                'order_id' => $this->extractOrderId($payload),
            ]);
        }

        if (! $this->is3dAuthSuccess($payload)) {
            return new VerificationResponse(
                success: false,
                paymentId: $this->extractOrderId($payload),
                status: 'failed',
                raw: ['callback' => $payload],
            );
        }

        $orderId = $this->extractOrderId($payload) ?? (string) $data->order('id', '');

        if ($orderId === '') {
            throw new PaymentFailedException(
                message: 'Tami dönüşünde orderId bulunamadı.',
                context: ['callback' => $payload],
            );
        }

        $response = $this->post('/payment/complete-3ds', ['orderId' => $orderId]);
        $approved = ($response['success'] ?? false) === true;

        return new VerificationResponse(
            success: $approved,
            paymentId: (string) ($response['bankReferenceNumber'] ?? $orderId),
            status: $approved ? 'success' : 'failed',
            raw: ['callback' => $payload, 'complete' => $response],
        );
    }

    /**
     * `hashedData` = base64(hmac_sha256(
     *   cardOrganization+cardBrand+cardType+maskedNumber+installmentCount+
     *   currencyCode+txnAmount+orderId+systemTime+success, secretKey)).
     *
     * Tami'nin kendi dokümantasyonu bu formülü hiç vermiyordu; alan
     * sırası ve anahtar (PG-Auth-Token'daki `secretKey`, ayrı bir anahtar
     * değil) bağımsız bir kaynaktan doğrulandı. Tami'nin resmî bir onayı
     * değil, gerçek bir sandbox'a karşı test edilmedi — dikkatli kullanın.
     *
     * @param  array<string, mixed>  $payload
     */
    protected function checkCallbackHash(array $payload): bool
    {
        $incoming = $this->pick($payload, ['hashedData']);

        if ($incoming === null) {
            return false;
        }

        $fields = implode('', [
            $this->pick($payload, ['cardOrg', 'cardOrganization'], '') ?? '',
            $this->pick($payload, ['cardBrand'], '') ?? '',
            $this->pick($payload, ['cardType'], '') ?? '',
            $this->pick($payload, ['maskedNumber'], '') ?? '',
            $this->pick($payload, ['installmentCount'], '') ?? '',
            $this->pick($payload, ['currency', 'currencyCode'], '') ?? '',
            $this->pick($payload, ['originalAmount', 'txnAmount', 'amount'], '') ?? '',
            $this->pick($payload, ['orderId', 'orderID'], '') ?? '',
            $this->pick($payload, ['systemTime'], '') ?? '',
            $this->pick($payload, ['success', 'status'], '') ?? '',
        ]);

        $expected = base64_encode(hash_hmac('sha256', $fields, $this->config->secretKey, true));

        return hash_equals($expected, $incoming);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function is3dAuthSuccess(array $payload): bool
    {
        return $this->pick($payload, ['success']) === 'true'
            || $this->pick($payload, ['mdStatus']) === '1';
    }

    /**
     * `performVerify()` kendi akışını yürüttüğü için bu yol kullanılmaz.
     *
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
        return new VerificationResponse(
            success: false,
            paymentId: $this->extractOrderId($payload),
            status: 'failed',
            raw: $payload,
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $provision
     */
    protected function mapProvisionResponse(array $payload, array $provision): VerificationResponse
    {
        $approved = ($provision['success'] ?? false) === true;

        return new VerificationResponse(
            success: $approved,
            paymentId: (string) ($provision['bankReferenceNumber'] ?? $this->extractOrderId($payload)),
            status: $approved ? 'success' : 'failed',
            raw: ['callback' => $payload, 'provision' => $provision],
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function extractOrderId(array $payload): ?string
    {
        return $this->pick($payload, ['orderId']);
    }

    /**
     * `performCreatePayment()` tamamen override edildiği için bu yol
     * kullanılmaz; abstract sözleşmeyi karşılamak için burada.
     *
     * @return array<string, mixed>
     */
    protected function build3dFormFields(CreatePaymentData $data): array
    {
        return [];
    }

    /**
     * İade. Tami dokümantasyonunda iptalle aynı uca (`/payment/reverse`)
     * gider; kısmi iade için `amount` alanı kullanılır.
     */
    protected function performRefund(RefundPaymentData $data): RefundResponse
    {
        return $this->mapReversal($this->post('/payment/reverse', $this->reversalRequest($data)));
    }

    /**
     * Gün sonu öncesi iptal. Dokümantasyonda iade ile **aynı uç**
     * (`/payment/reverse`) — protokol seviyesinde bir fark bulunamadı.
     */
    public function cancel(RefundPaymentData $data): RefundResponse
    {
        return $this->mapReversal($this->post('/payment/reverse', $this->reversalRequest($data)));
    }

    /**
     * @return array<string, mixed>
     */
    protected function reversalRequest(RefundPaymentData $data): array
    {
        $request = ['orderId' => $data->paymentId];

        if (($amount = $data->money()) !== null) {
            $request['amount'] = $amount->toDecimalString();
        }

        if ($data->reason !== null && $data->reason !== '') {
            $request['reason'] = $data->reason;
        }

        return $request;
    }

    /**
     * @param  array<string, mixed>  $response
     */
    protected function mapReversal(array $response): RefundResponse
    {
        $approved = ($response['success'] ?? false) === true;

        return new RefundResponse(
            success: $approved,
            refundId: $approved ? (string) ($response['bankReferenceNumber'] ?? '') : null,
            errorMessage: $approved ? null : $this->errorMessage($response),
            raw: $response,
        );
    }

    /**
     * Ön provizyonu kapatır.
     *
     * **Doğrulanmadı:** dokümantasyondaki "Ön Otorizasyon Kapama" sayfasının
     * içeriğini okuyamadım; uç, `pre-auth`/`auth` adlandırma kalıbından
     * (`/payment/post-auth`) tahmin edildi. Gerçek sandbox'a karşı
     * doğrulanana kadar dikkatli kullanın.
     */
    public function capture(CapturePaymentData $data): PaymentResponse
    {
        $request = ['orderId' => $data->orderId];

        if (($amount = $data->money()) !== null) {
            $request['amount'] = $amount->toDecimalString();
        }

        $response = $this->post('/payment/post-auth', $request);

        return $this->mapPaymentResponse($response, $data->orderId);
    }

    /**
     * Sipariş durumunu sorgular.
     */
    public function status(string $orderId, array $context = []): StatusResponse
    {
        $response = $this->post('/payment/query', [
            'orderId' => $orderId,
            'isTransactionDetail' => true,
        ]);

        if (($response['success'] ?? false) !== true) {
            return StatusResponse::notFound($orderId, $response);
        }

        $currency = (string) ($response['currency'] ?? 'TRY');
        $amount = $response['amount'] ?? null;

        return new StatusResponse(
            found: true,
            status: $this->mapOrderStatus(
                (string) ($response['orderStatus'] ?? ''),
                (string) ($response['paymentStatus'] ?? ''),
            ),
            orderId: $orderId,
            paymentId: (string) ($response['correlationId'] ?? $orderId),
            amount: is_numeric($amount) ? Money::fromDecimal((float) $amount, $currency) : null,
            installment: isset($response['installmentCount']) ? (int) $response['installmentCount'] : null,
            transactionTime: isset($response['orderDate']) ? (string) $response['orderDate'] : null,
            raw: $response,
        );
    }

    /**
     * Tami'nin `orderStatus`/`paymentStatus` enum çiftini tek bir
     * normalleştirilmiş duruma indirger.
     */
    protected function mapOrderStatus(string $orderStatus, string $paymentStatus): string
    {
        if (in_array($paymentStatus, ['FAIL', 'TIME_OUT'], true)) {
            return StatusResponse::STATUS_FAILED;
        }

        return match ($orderStatus) {
            'REVERSE' => StatusResponse::STATUS_CANCELLED,
            'REFUND', 'PARTIAL_REFUND' => StatusResponse::STATUS_REFUNDED,
            'PRE_AUTH' => StatusResponse::STATUS_PRE_AUTHORIZED,
            'AUTH', 'POST_AUTH' => StatusResponse::STATUS_PAID,
            default => StatusResponse::STATUS_UNKNOWN,
        };
    }

    /**
     * @param  array<string, mixed>  $response
     */
    protected function mapPaymentResponse(array $response, string $orderId): PaymentResponse
    {
        $approved = ($response['success'] ?? false) === true;

        return new PaymentResponse(
            success: $approved,
            paymentId: (string) ($response['bankReferenceNumber'] ?? $orderId),
            errorMessage: $approved ? null : $this->errorMessage($response),
            raw: $response,
            errorCode: $approved ? null : $this->pick($response, ['errorCode']),
        );
    }

    /**
     * @param  array<string, mixed>  $response
     */
    protected function errorMessage(array $response): ?string
    {
        return $this->pick($response, ['errorMessage']);
    }

    /**
     * `PG-Auth-Token` = "merchantNumber:terminalNumber:hash",
     * hash = base64(sha256(merchantNumber+terminalNumber+secretKey)).
     *
     * @return array<string, string>
     */
    protected function headers(): array
    {
        return [
            'CorrelationId' => 'Correlation'.random_int(100000, 999999),
            'PG-Auth-Token' => $this->authToken(),
        ];
    }

    protected function authToken(): string
    {
        $this->config->require(['merchantId', 'terminalId', 'secretKey']);

        $hash = base64_encode(hash(
            'sha256',
            $this->config->merchantId.$this->config->terminalId.$this->config->secretKey,
            true,
        ));

        return "{$this->config->merchantId}:{$this->config->terminalId}:{$hash}";
    }

    /**
     * `securityHash` = HS512 ile imzalanmış bir JWT (JWS compact
     * serileştirme). Header `{"alg":"HS512","typ":"JWT","kid":<username>}`,
     * payload gövdenin (bu alan hariç) JSON'u, anahtar `password`
     * alanındaki JWK `k` değeri (base64url kodlu ham bayt).
     *
     * @param  array<string, mixed>  $body  `securityHash` alanı OLMADAN gövde
     *
     * @throws PaymentFailedException Gövde JSON'a kodlanamazsa
     */
    protected function securityHash(array $body): string
    {
        $this->config->require(['username', 'password']);

        $header = ['alg' => 'HS512', 'typ' => 'JWT', 'kid' => $this->config->username];
        $headerJson = json_encode($header, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $payloadJson = json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        if ($headerJson === false || $payloadJson === false) {
            throw new PaymentFailedException('Tami isteği JSON olarak kodlanamadı.');
        }

        $signingInput = self::base64UrlEncode($headerJson).'.'.self::base64UrlEncode($payloadJson);
        $key = self::base64UrlDecode($this->config->password);
        $signature = hash_hmac('sha512', $signingInput, $key, true);

        return $signingInput.'.'.self::base64UrlEncode($signature);
    }

    protected static function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    protected static function base64UrlDecode(string $data): string
    {
        $decoded = base64_decode(strtr($data, '-_', '+/'), true);

        return $decoded === false ? '' : $decoded;
    }

    /**
     * `securityHash`'i hesaplayıp ekler, `PG-Auth-Token`/`CorrelationId`
     * başlıklarıyla JSON POST eder.
     *
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     */
    protected function post(string $path, array $body): array
    {
        $body['securityHash'] = $this->securityHash($body);

        return $this->client->postJson($this->url($path), $body, $this->headers());
    }

    protected function url(string $path): string
    {
        return rtrim($this->config->endpoint('payment_api'), '/').$path;
    }
}
