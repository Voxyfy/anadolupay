<?php

declare(strict_types=1);

namespace Voxyfy\AnadoluPay\Gateways\Provider;

use Voxyfy\AnadoluPay\Contracts\SupportsBinQuery;
use Voxyfy\AnadoluPay\Contracts\SupportsInstallmentQuery;
use Voxyfy\AnadoluPay\Contracts\SupportsOrderHistory;
use Voxyfy\AnadoluPay\Contracts\SupportsPreAuthorization;
use Voxyfy\AnadoluPay\Contracts\SupportsStatusQuery;
use Voxyfy\AnadoluPay\DTO\BinResponse;
use Voxyfy\AnadoluPay\DTO\CapturePaymentData;
use Voxyfy\AnadoluPay\DTO\CreatePaymentData;
use Voxyfy\AnadoluPay\DTO\InstallmentOption;
use Voxyfy\AnadoluPay\DTO\PaymentResponse;
use Voxyfy\AnadoluPay\DTO\RefundPaymentData;
use Voxyfy\AnadoluPay\DTO\RefundResponse;
use Voxyfy\AnadoluPay\DTO\StatusResponse;
use Voxyfy\AnadoluPay\DTO\VerificationResponse;
use Voxyfy\AnadoluPay\Exceptions\PaymentFailedException;
use Voxyfy\AnadoluPay\Gateways\Bank\AbstractBankGateway;
use Voxyfy\AnadoluPay\Support\Bank\OrderStatus;
use Voxyfy\AnadoluPay\Support\Money;

/**
 * Craftgate Driver'ı
 *
 * Craftgate bir bankanın sanal POS'u değil, birden çok POS'u tek API
 * arkasında toplayan bir ödeme orkestrasyon platformudur. Bu yüzden akış
 * paketteki banka driver'larından iki noktada ayrılır:
 *
 *   - Müşteri bir banka geçidine form POST edilmez. `3ds-init` ucu hazır
 *     bir HTML sayfası (base64) döner; sayfa doğrudan tarayıcıya basılır.
 *     Bu sayfa `PaymentResponse::$htmlContent` içinde taşınır.
 *   - Tutarlar kuruş değil, ondalık sayı olarak gönderilir (`price`).
 *
 * Kimlik doğrulama (v1):
 *
 *     x-signature = base64( sha256( baseUrl + path + apiKey + secretKey + rndKey + gövde ) )
 *
 * İmza gövdenin **gönderilen baytları** üzerinden hesaplanır; bu yüzden
 * gövde burada bir kez kodlanır ve aynı dizgi hem imzalanıp hem gönderilir.
 * Gövdeyi imzaladıktan sonra yeniden kodlamak "Signature is not equal!"
 * hatasının en yaygın sebebidir.
 *
 * 3D dönüş imzası ayrı bir anahtarla (3D Secure Callback Key) ve farklı
 * bir şemayla hesaplanır: `sha256_hex` ve `###` ayracı.
 *
 * Kaynak: craftgate/craftgate-php-client (`src/Util/Signature.php`,
 * `src/Adapter/PaymentAdapter.php`) ve craftgate-java-client. Her iki
 * imza şeması da o depoların resmi test vektörleriyle doğrulanmıştır.
 */
class CraftgateGateway extends AbstractBankGateway implements SupportsBinQuery, SupportsInstallmentQuery, SupportsOrderHistory, SupportsPreAuthorization, SupportsStatusQuery
{
    /** Ödemenin başarıyla tamamlandığını bildiren durum. */
    protected const STATUS_SUCCESS = 'SUCCESS';

    /**
     * 3D dönüşünde ödemenin hâlâ tamamlanmayı beklediğini bildiren durum.
     *
     * Craftgate bazı kurulumlarda provizyonu kendisi kapatır; yalnızca bu
     * değer geldiğinde `3ds-complete` isteği gönderilmelidir.
     */
    protected const COMPLETE_STATUS_WAITING = 'WAITING';

    /**
     * 3D akışı: Craftgate hazır bir HTML sayfası döner.
     */
    protected function performCreatePayment(CreatePaymentData $data): PaymentResponse
    {
        if ($data->paymentModel === CreatePaymentData::MODEL_NON_SECURE) {
            return $this->nonSecurePayment($data);
        }

        $response = $this->post('/payment/v1/card-payments/3ds-init', $this->build3dFormFields($data));

        $html = $this->pick($response, ['htmlContent']);
        $redirect = $this->pick($response, ['redirectUrl']);

        if ($html === null && $redirect === null) {
            throw new PaymentFailedException(
                message: $this->errorMessage($response) ?? 'Craftgate 3D oturumu açılamadı.',
                context: ['response' => $response],
            );
        }

        return new PaymentResponse(
            success: true,
            paymentId: $this->pick($response, ['paymentId']) ?? $data->orderId,
            redirectUrl: $redirect,
            raw: $response,
            // Sayfa base64 kodlu gelir; çözülemezse ham hâliyle taşınır.
            htmlContent: $html !== null ? (base64_decode($html, true) ?: $html) : null,
        );
    }

    /**
     * `3ds-init` istek gövdesini üretir.
     *
     * Craftgate'e form POST edilmediği için bu metot bir HTML form alanı
     * kümesi değil, API istek gövdesi döndürür.
     *
     * @return array<string, mixed>
     */
    protected function build3dFormFields(CreatePaymentData $data): array
    {
        return $this->paymentRequest($data) + [
            'callbackUrl' => $this->successUrl($data),
        ];
    }

    /**
     * 3D'siz doğrudan provizyon.
     */
    protected function nonSecurePayment(CreatePaymentData $data): PaymentResponse
    {
        $response = $this->post('/payment/v1/card-payments', $this->paymentRequest($data));

        return $this->mapPaymentResponse($response, $data->orderId);
    }

    /**
     * Ortak ödeme isteği gövdesi.
     *
     * @return array<string, mixed>
     */
    protected function paymentRequest(CreatePaymentData $data): array
    {
        $this->config->require(['username', 'secretKey']);

        $card = $this->requireCard($data);
        $money = $data->money();

        return [
            'price' => $this->amount($money),
            'paidPrice' => $this->amount($money),
            'currency' => strtoupper($data->currency),
            'installment' => $data->installments(),
            'conversationId' => $data->orderId,
            'externalId' => $data->orderId,
            'clientIp' => $data->clientIp(),
            'paymentGroup' => (string) $this->config->extra('payment_group', 'PRODUCT'),
            'paymentPhase' => $data->preAuthorization ? 'PRE_AUTH' : 'AUTH',
            'card' => [
                'cardHolderName' => $card->holderName ?? '',
                'cardNumber' => $card->number,
                'expireMonth' => $card->expireMonth,
                'expireYear' => $card->expireYearLong(),
                'cvc' => $card->cvv,
            ],
        ];
    }

    /**
     * 3D dönüş imzası.
     *
     * Üye işyeri panelindeki **3D Secure Callback Key** ile hesaplanır;
     * API imzasının anahtarı ve algoritması farklıdır.
     *
     * @param  array<string, mixed>  $payload
     */
    protected function checkCallbackHash(array $payload): bool
    {
        $incoming = $this->pick($payload, ['hash']);

        if ($incoming === null) {
            return false;
        }

        $this->config->require(['password']);

        $fields = ['status', 'completeStatus', 'paymentId', 'conversationData', 'conversationId', 'callbackStatus'];
        $hashString = $this->config->password;

        foreach ($fields as $field) {
            $hashString .= '###'.($this->pick($payload, [$field], '') ?? '');
        }

        return hash_equals(hash('sha256', $hashString), $incoming);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function is3dAuthSuccess(array $payload): bool
    {
        return $this->pick($payload, ['status']) === self::STATUS_SUCCESS;
    }

    /**
     * Ödemenin ikinci adıma ihtiyacı olup olmadığını Craftgate bildirir.
     *
     * @param  array<string, mixed>  $payload
     */
    protected function requiresProvision(array $payload): bool
    {
        return $this->pick($payload, ['completeStatus']) === self::COMPLETE_STATUS_WAITING;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    protected function provision(array $payload): array
    {
        return $this->post('/payment/v1/card-payments/3ds-complete', [
            'paymentId' => (int) ($this->pick($payload, ['paymentId']) ?? 0),
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function mapCallbackResponse(array $payload): VerificationResponse
    {
        return new VerificationResponse(
            success: true,
            paymentId: $this->pick($payload, ['paymentId']),
            status: 'success',
            raw: ['callback' => $payload],
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $provision
     */
    protected function mapProvisionResponse(array $payload, array $provision): VerificationResponse
    {
        $approved = $this->pick($provision, ['paymentStatus']) === self::STATUS_SUCCESS;

        return new VerificationResponse(
            success: $approved,
            paymentId: $this->pick($provision, ['id']) ?? $this->pick($payload, ['paymentId']),
            status: $approved ? 'success' : 'failed',
            raw: ['callback' => $payload, 'provision' => $provision],
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function extractOrderId(array $payload): ?string
    {
        return $this->pick($payload, ['conversationId']);
    }

    /**
     * İade.
     *
     * Craftgate'te iki ayrı uç vardır: `/refunds` ödemenin tamamını iade
     * eder, kısmi iade ise işlem (transaction) bazındadır. Kısmi iade için
     * `metadata['payment_transaction_id']` verilmelidir; Craftgate bir
     * ödemeyi birden çok satıcı işlemine bölebildiği için hangi işlemin
     * iade edileceğini paket kendi başına seçemez.
     *
     * Gün içi iptal ayrı bir uç değildir; Craftgate henüz mutabakata
     * girmemiş işlemi kendisi void olarak geçer.
     */
    protected function performRefund(RefundPaymentData $data): RefundResponse
    {
        $amount = $data->money();
        $transactionId = $data->meta('payment_transaction_id');

        if ($amount !== null && $transactionId !== null) {
            $response = $this->post('/payment/v1/refund-transactions', [
                'paymentTransactionId' => (int) $transactionId,
                'refundPrice' => $this->amount($amount),
                'conversationId' => $data->meta('conversation_id'),
                'refundDestinationType' => 'PROVIDER',
            ]);
        } else {
            if ($amount !== null) {
                throw new PaymentFailedException(
                    message: "Craftgate'te kısmi iade için metadata['payment_transaction_id'] zorunludur.",
                    context: ['bank' => $this->config->bank, 'payment_id' => $data->paymentId],
                );
            }

            $response = $this->post('/payment/v1/refunds', [
                'paymentId' => (int) $data->paymentId,
                'conversationId' => $data->meta('conversation_id'),
                'refundDestinationType' => 'PROVIDER',
            ]);
        }

        $approved = $this->pick($response, ['status']) === self::STATUS_SUCCESS;

        return new RefundResponse(
            success: $approved,
            refundId: $this->pick($response, ['id']),
            errorMessage: $approved ? $this->errorMessage($response) : ($this->errorMessage($response) ?? 'İade reddedildi.'),
            raw: $response,
        );
    }

    /**
     * Ön provizyonu kapatır.
     *
     * `orderId` burada Craftgate'in sayısal `paymentId` değeridir; ön
     * provizyon yanıtındaki `id` alanından gelir.
     */
    public function capture(CapturePaymentData $data): PaymentResponse
    {
        $request = [];

        if (($amount = $data->money()) !== null) {
            $request['paidPrice'] = $this->amount($amount);
        }

        $response = $this->post('/payment/v1/card-payments/'.rawurlencode($data->orderId).'/post-auth', $request);

        return $this->mapPaymentResponse($response, $data->orderId);
    }

    /**
     * Ödeme durumunu sorgular.
     *
     * Sayısal bir değer Craftgate `paymentId`si sayılır ve doğrudan
     * okunur; aksi hâlde `conversationId` ile raporlama ucunda aranır.
     */
    public function status(string $orderId, array $context = []): StatusResponse
    {
        $paymentId = $context['payment_id'] ?? ($this->isPaymentId($orderId) ? $orderId : null);

        if ($paymentId !== null) {
            $payment = $this->get('/payment-reporting/v1/payments/'.rawurlencode((string) $paymentId));
        } else {
            $found = $this->get('/payment-reporting/v1/payments', [
                'conversationId' => $orderId,
                'page' => 0,
                'size' => 1,
            ]);

            $items = $found['items'] ?? null;
            $payment = is_array($items) && is_array($items[0] ?? null) ? $items[0] : [];
        }

        $status = $this->pick($payment, ['paymentStatus']);

        if ($status === null) {
            return StatusResponse::notFound($orderId, $payment);
        }

        $currency = $this->pick($payment, ['currency']) ?? 'TRY';

        return new StatusResponse(
            found: true,
            status: OrderStatus::map($status, OrderStatus::CRAFTGATE),
            orderId: $this->pick($payment, ['conversationId']) ?? $orderId,
            paymentId: $this->pick($payment, ['id']),
            amount: ($paid = $this->pick($payment, ['paidPrice', 'price'])) !== null && is_numeric($paid)
                ? Money::fromDecimal($paid, $currency)
                : null,
            installment: ($installment = $this->pick($payment, ['installment'])) !== null
                ? (int) $installment
                : null,
            transactionTime: $this->pick($payment, ['createdDate']),
            maskedCardNumber: $this->maskedCard($payment),
            errorMessage: $this->errorMessage($payment),
            raw: $payment,
        );
    }

    /**
     * Bir ödemenin işlem dökümü.
     *
     * @return array<string, mixed>
     */
    public function orderHistory(string $orderId, array $context = []): array
    {
        return $this->get('/payment-reporting/v1/payments/'.rawurlencode($orderId).'/transactions');
    }

    /**
     * BIN sorgusu.
     */
    public function binLookup(string $bin, array $context = []): BinResponse
    {
        $response = $this->get('/installment/v1/bins/'.rawurlencode($bin));

        $bankName = $this->pick($response, ['bankName']);

        if ($bankName === null) {
            return new BinResponse(found: false, raw: $response);
        }

        $type = $this->pick($response, ['cardType']);

        return new BinResponse(
            found: true,
            bankName: $bankName,
            brand: $this->pick($response, ['cardAssociation', 'cardBrand']),
            // Craftgate CREDIT_CARD / DEBIT_CARD / PREPAID_CARD döner.
            type: $type !== null ? strtolower(str_replace('_CARD', '', $type)) : null,
            commercial: isset($response['commercial']) ? (bool) $response['commercial'] : null,
            raw: $response,
        );
    }

    /**
     * Taksit seçenekleri.
     *
     * @return list<InstallmentOption>
     */
    public function installmentOptions(Money $amount, ?string $bin = null): array
    {
        $query = ['price' => $this->amount($amount), 'currency' => $amount->currency];

        if ($bin !== null && $bin !== '') {
            $query['binNumber'] = $bin;
        }

        $response = $this->get('/installment/v1/installments', $query);

        $items = $response['items'] ?? [];

        if (! is_array($items)) {
            return [];
        }

        $options = [];

        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }

            $bankName = $this->pick($item, ['bankName']);
            $prices = $item['installmentPrices'] ?? [];

            if (! is_array($prices)) {
                continue;
            }

            foreach ($prices as $price) {
                if (! is_array($price)) {
                    continue;
                }

                $count = (int) ($this->pick($price, ['installmentNumber']) ?? 0);

                if ($count < 1) {
                    continue;
                }

                $total = $this->pick($price, ['totalPrice']);
                $monthly = $this->pick($price, ['installmentPrice']);

                $options[] = new InstallmentOption(
                    count: $count,
                    totalPrice: $total !== null && is_numeric($total)
                        ? Money::fromDecimal($total, $amount->currency)
                        : null,
                    monthlyPrice: $monthly !== null && is_numeric($monthly)
                        ? Money::fromDecimal($monthly, $amount->currency)
                        : null,
                    bankName: $bankName,
                    raw: $price,
                );
            }
        }

        return $options;
    }

    /**
     * Craftgate webhook imzasını doğrular.
     *
     * İmza gövdenin tamamı üzerinden değil, dört alanın birleşimi
     * üzerinden hesaplanır. Anahtar üye işyeri panelindeki
     * **Merchant Hook Key**'dir.
     *
     * @param  array<string, mixed>  $payload
     */
    public function verifyWebhookSignature(string $incomingSignature, array $payload, ?string $hookKey = null): bool
    {
        $key = $hookKey ?? (string) $this->config->extra('merchant_hook_key', '');

        if ($key === '') {
            return false;
        }

        $hashString = '';

        foreach (['eventType', 'eventTimestamp', 'status', 'payloadId'] as $field) {
            $hashString .= $this->pick($payload, [$field], '') ?? '';
        }

        return hash_equals(
            base64_encode(hash_hmac('sha256', $hashString, $key, true)),
            $incomingSignature,
        );
    }

    /**
     * Ödeme yanıtını normalleştirir.
     *
     * @param  array<string, mixed>  $response
     */
    protected function mapPaymentResponse(array $response, string $orderId): PaymentResponse
    {
        $approved = $this->pick($response, ['paymentStatus']) === self::STATUS_SUCCESS;

        return new PaymentResponse(
            success: $approved,
            paymentId: $this->pick($response, ['id']) ?? $orderId,
            errorMessage: $approved ? null : ($this->errorMessage($response) ?? 'Ödeme reddedildi.'),
            raw: $response,
            errorCode: $approved ? null : $this->errorCode($response),
        );
    }

    /**
     * Tutarı Craftgate'in beklediği ondalık sayıya çevirir.
     *
     * Değer kuruş cinsinden tam sayıdan üretilir; ödeme yolunda float
     * aritmetiği yapılmaz.
     */
    protected function amount(Money $money): float
    {
        return (float) $money->toDecimalString();
    }

    /**
     * @param  array<string, mixed>  $request
     * @return array<string, mixed>
     */
    protected function post(string $path, array $request): array
    {
        // İmza gönderilen baytlar üzerinden hesaplandığı için gövde burada
        // bir kez kodlanır ve aynı dizgi hem imzalanır hem gönderilir.
        $body = json_encode(
            array_filter($request, static fn (mixed $value): bool => $value !== null),
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        );

        if ($body === false) {
            throw new PaymentFailedException('Craftgate isteği JSON olarak kodlanamadı.');
        }

        return $this->unwrap($this->client->send(
            url: $this->url($path),
            body: $body,
            headers: $this->headers($path, $body) + ['Content-Type' => 'application/json'],
        ));
    }

    /**
     * @param  array<string, scalar>  $query
     * @return array<string, mixed>
     */
    protected function get(string $path, array $query = []): array
    {
        if ($query !== []) {
            // Craftgate imzayı yolun kodlanmamış hâli üzerinden hesaplar ve
            // sorgu dizgisinde boşluğu `+` değil `%20` bekler.
            $path .= '?'.str_replace('+', '%20', http_build_query($query));
        }

        return $this->unwrap($this->client->get(
            url: $this->url($path),
            headers: $this->headers($path, ''),
        ));
    }

    /**
     * İstek başlıkları ve imza.
     *
     * @return array<string, string>
     */
    protected function headers(string $path, string $body): array
    {
        $this->config->require(['username', 'secretKey']);

        $randomKey = $this->randomString(32);

        return [
            'x-api-key' => $this->config->username,
            'x-rnd-key' => $randomKey,
            'x-auth-version' => 'v1',
            'x-signature' => $this->signature($path, $randomKey, $body),
            'Accept' => 'application/json',
            'lang' => $this->config->lang,
        ];
    }

    /**
     * x-signature = base64( sha256( baseUrl + path + apiKey + secretKey + rndKey + gövde ) )
     */
    protected function signature(string $path, string $randomKey, string $body): string
    {
        return base64_encode(hash('sha256', implode('', [
            $this->baseUrl(),
            urldecode($path),
            $this->config->username,
            $this->config->secretKey,
            $randomKey,
            $body,
        ]), true));
    }

    protected function url(string $path): string
    {
        return $this->baseUrl().$path;
    }

    protected function baseUrl(): string
    {
        return rtrim($this->config->endpoint('payment_api'), '/');
    }

    /**
     * Craftgate yanıtları `data` zarfı içinde döner; hatalar `errors`
     * altında gelir ve HTTP durum kodu 4xx olur.
     *
     * @param  array<string, mixed>  $response
     * @return array<string, mixed>
     */
    protected function unwrap(array $response): array
    {
        if (isset($response['errors']) && is_array($response['errors'])) {
            return $response['errors'];
        }

        $data = $response['data'] ?? null;

        return is_array($data) ? $data : $response;
    }

    /**
     * @param  array<string, mixed>  $response
     */
    protected function errorMessage(array $response): ?string
    {
        return $this->pick($response, ['errorDescription', 'errorMessage'])
            ?? $this->pick(
                is_array($response['paymentError'] ?? null) ? $response['paymentError'] : [],
                ['errorDescription', 'errorMessage'],
            );
    }

    /**
     * @param  array<string, mixed>  $response
     */
    protected function errorCode(array $response): ?string
    {
        return $this->pick($response, ['errorCode'])
            ?? $this->pick(
                is_array($response['paymentError'] ?? null) ? $response['paymentError'] : [],
                ['errorCode'],
            );
    }

    /**
     * @param  array<string, mixed>  $payment
     */
    protected function maskedCard(array $payment): ?string
    {
        $bin = $this->pick($payment, ['binNumber']);
        $last = $this->pick($payment, ['lastFourDigits']);

        return $bin !== null && $last !== null ? $bin.'******'.$last : null;
    }

    /**
     * Craftgate `paymentId` değerleri sayısaldır; sipariş numarası
     * (conversationId) genellikle değildir.
     */
    protected function isPaymentId(string $value): bool
    {
        return $value !== '' && ctype_digit($value);
    }
}
