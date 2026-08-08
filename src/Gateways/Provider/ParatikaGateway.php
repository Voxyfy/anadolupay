<?php

declare(strict_types=1);

namespace Voxyfy\AnadoluPay\Gateways\Provider;

use Voxyfy\AnadoluPay\Contracts\SupportsBinQuery;
use Voxyfy\AnadoluPay\Contracts\SupportsCancellation;
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
 * Paratika (Payten / Asseco) Driver'ı
 *
 * Paratika form-encoded POST ile çalışır ve **istek imzası kullanmaz**;
 * kimlik doğrulama her isteğe eklenen üç alandır (`MERCHANT`,
 * `MERCHANTUSER`, `MERCHANTPASSWORD`). İmza yalnızca 3D dönüşünde vardır.
 *
 * Akış her modelde bir **oturum anahtarıyla** başlar:
 *
 *   1. `ACTION=SESSIONTOKEN` ile sepet ve müşteri bilgisi bildirilir,
 *      karşılığında `sessionToken` alınır.
 *   2. Ödeme modeline göre:
 *      - `3d_pay`  → tarayıcı `post/sale3d/{token}` adresine POST eder;
 *                    Paratika hem 3D doğrulamayı hem satışı yapar.
 *      - `3d`      → tarayıcı `post/auth3d/{token}` adresine POST eder;
 *                    yalnızca 3D doğrulama yapılır, satış dönüşte
 *                    `ACTION=SALE` ile aynı oturum üzerinden tamamlanır.
 *      - `3d_host` → müşteri Paratika'nın ödeme sayfasına yönlendirilir.
 *      - `regular` → kart bilgisiyle doğrudan `ACTION=SALE`.
 *
 * **Dönüş imzasında iki alan gelir ve biri tuzaktır:** `SD_SHA512`
 * dokümanda "Deprecated / Legacy - Do not use!" olarak işaretlidir ama
 * örnek yanıtlarda önce göründüğü için yaygın biçimde yanlışlıkla
 * kullanılıyor. Bu driver güncel olanı, `sdSha512`'yi doğrular:
 *
 *     sdSha512 = sha512_hex( merchantPaymentId|customerId|sessionToken
 *                            |responseCode|random|secretKey )
 *
 * Tutarlar ondalık, para birimi ISO alfabetik koddur (`TRY`). Başarı
 * kodu `responseCode = "00"`tır.
 *
 * Kaynak: https://docs.paratika.com.tr ve
 * https://entegrasyon.paratika.com.tr/paratika/api/v2/doc — ikisi de
 * herkese açıktır. Resmî örnek kod: github.com/PaytenASEE/paratika-samples
 */
class ParatikaGateway extends AbstractBankGateway implements SupportsBinQuery, SupportsCancellation, SupportsInstallmentQuery, SupportsOrderHistory, SupportsPreAuthorization, SupportsStatusQuery
{
    /** Onaylandı. */
    protected const RESPONSE_APPROVED = '00';

    /** Satış işlemi tipleri (ana işlem). */
    protected const PRIMARY_TYPES = ['SALE', 'PREAUTH', 'POSTAUTH'];

    /**
     * Ödeme akışı her modelde oturum anahtarıyla başlar.
     */
    protected function performCreatePayment(CreatePaymentData $data): PaymentResponse
    {
        if ($data->paymentModel === CreatePaymentData::MODEL_NON_SECURE) {
            return $this->nonSecurePayment($data);
        }

        $sessionToken = $this->createSession($data);

        // 3D Host: kart bilgisi de dâhil her şeyi Paratika toplar.
        if ($data->paymentModel === CreatePaymentData::MODEL_3D_HOST) {
            return new PaymentResponse(
                success: true,
                paymentId: $data->orderId,
                redirectUrl: $this->tokenUrl('gateway_3d_host', $sessionToken),
                raw: ['session_token' => $sessionToken],
            );
        }

        $card = $this->requireCard($data);

        return new PaymentResponse(
            success: true,
            paymentId: $data->orderId,
            raw: ['session_token' => $sessionToken],
            formAction: $this->tokenUrl(
                // 3D Pay tek adımda satışı da tamamlar; klasik 3D yalnızca
                // kimlik doğrular, satış dönüşte yapılır.
                $data->paymentModel === CreatePaymentData::MODEL_3D_PAY ? 'gateway_3d' : 'gateway_3d_auth',
                $sessionToken,
            ),
            formFields: [
                'cardOwner' => $card->holderName ?? '',
                'pan' => $card->number,
                'expiryMonth' => $card->expireMonth,
                'expiryYear' => $card->expireYearLong(),
                'cvv' => $card->cvv,
                'installmentCount' => $data->installments(),
                'callbackUrl' => $this->successUrl($data),
            ],
        );
    }

    /**
     * Oturum anahtarı üretir.
     *
     * @throws PaymentFailedException Oturum açılamazsa
     */
    protected function createSession(CreatePaymentData $data): string
    {
        $money = $data->money();

        $request = [
            'ACTION' => 'SESSIONTOKEN',
            'SESSIONTYPE' => 'PAYMENTSESSION',
            'MERCHANTPAYMENTID' => $data->orderId,
            'AMOUNT' => $money->toDecimalString(),
            'CURRENCY' => strtoupper($data->currency),
            'RETURNURL' => $this->successUrl($data),
            'CUSTOMER' => $this->customerId($data),
            'CUSTOMERIP' => $data->clientIp(),
            'ORDERITEMS' => $this->orderItems($data),
        ];

        foreach ([
            'CUSTOMERNAME' => 'name',
            'CUSTOMEREMAIL' => 'email',
            'CUSTOMERPHONE' => 'phone',
        ] as $field => $key) {
            $value = $data->customer[$key] ?? null;

            if (is_scalar($value) && (string) $value !== '') {
                $request[$field] = (string) $value;
            }
        }

        $response = $this->post($request);
        $sessionToken = $this->pick($response, ['sessionToken']);

        if ($sessionToken === null) {
            throw new PaymentFailedException(
                message: $this->errorMessage($response) ?? 'Paratika oturum anahtarı alınamadı.',
                context: ['response' => $response],
            );
        }

        return $sessionToken;
    }

    /**
     * Sepet satırları.
     *
     * Paratika JSON taşıyan alanları **URL kodlanmış** bekler; form
     * gövdesi zaten kodlandığı için değer iki kez kodlanmış olur. Bu,
     * resmî Java örneğindeki davranıştır (`ParatikaUtil`) ve `EXTRA`
     * alanının dokümantasyonunda açıkça belirtilir.
     */
    protected function orderItems(CreatePaymentData $data): string
    {
        $items = $data->metadata['items'] ?? null;

        if (! is_array($items) || $items === []) {
            // Satır bildirilmediyse siparişin tamamı tek satır sayılır;
            // satırların toplamı AMOUNT ile eşleşmek zorundadır.
            $items = [[
                'productCode' => $data->orderId,
                'name' => (string) ($data->metadata['description'] ?? $data->orderId),
                'quantity' => 1,
                'amount' => (float) $data->money()->toDecimalString(),
            ]];
        }

        $json = json_encode(array_values($items), JSON_UNESCAPED_UNICODE);

        if ($json === false) {
            throw new PaymentFailedException('Paratika sepet bilgisi JSON olarak kodlanamadı.');
        }

        return rawurlencode($json);
    }

    /**
     * 3D'siz doğrudan satış.
     */
    protected function nonSecurePayment(CreatePaymentData $data): PaymentResponse
    {
        $card = $this->requireCard($data);
        $sessionToken = $this->createSession($data);

        $response = $this->post([
            'ACTION' => $data->preAuthorization ? 'PREAUTH' : 'SALE',
            'SESSIONTOKEN' => $sessionToken,
            'CARDPAN' => $card->number,
            'CARDEXPIRY' => $card->expiry('m.Y'),
            'CARDCVV' => $card->cvv,
            'NAMEONCARD' => $card->holderName ?? '',
            'INSTALLMENTS' => (string) $data->installments(),
        ]);

        return $this->mapPaymentResponse($response, $data->orderId, ['session_token' => $sessionToken]);
    }

    /**
     * 3D formu `performCreatePayment()` içinde oturum anahtarıyla
     * üretildiği için bu yol kullanılmaz.
     *
     * @return array<string, mixed>
     */
    protected function build3dFormFields(CreatePaymentData $data): array
    {
        return ['SESSIONTOKEN' => $this->createSession($data)];
    }

    /**
     * Dönüş imzası.
     *
     * @param  array<string, mixed>  $payload
     */
    protected function checkCallbackHash(array $payload): bool
    {
        $incoming = $this->pick($payload, ['sdSha512']);

        if ($incoming === null) {
            return false;
        }

        $this->config->require(['secretKey']);

        $hashString = implode('|', [
            $this->pick($payload, ['merchantPaymentId'], '') ?? '',
            $this->pick($payload, ['customerId'], '') ?? '',
            $this->pick($payload, ['sessionToken'], '') ?? '',
            $this->pick($payload, ['responseCode'], '') ?? '',
            $this->pick($payload, ['random'], '') ?? '',
            $this->config->secretKey,
        ]);

        return hash_equals(hash('sha512', $hashString), $incoming);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function is3dAuthSuccess(array $payload): bool
    {
        return $this->pick($payload, ['responseCode']) === self::RESPONSE_APPROVED;
    }

    /**
     * Klasik 3D modelinde satış dönüşte ayrıca yapılır; 3D Pay ve
     * 3D Host modellerinde Paratika satışı kendisi tamamlar.
     *
     * @param  array<string, mixed>  $payload
     */
    protected function requiresProvision(array $payload): bool
    {
        // `auth3DToken` yalnızca ayrık 3D doğrulama akışında döner.
        return $this->pick($payload, ['auth3DToken']) !== null;
    }

    /**
     * Doğrulanmış oturum üzerinden satışı tamamlar.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    protected function provision(array $payload): array
    {
        $sessionToken = $this->pick($payload, ['sessionToken']);

        if ($sessionToken === null) {
            throw new PaymentFailedException(
                message: 'Paratika satışı için dönüşte sessionToken bekleniyordu.',
                context: ['bank' => $this->config->bank],
            );
        }

        return $this->post([
            'ACTION' => 'SALE',
            'SESSIONTOKEN' => $sessionToken,
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function mapCallbackResponse(array $payload): VerificationResponse
    {
        return new VerificationResponse(
            success: true,
            paymentId: $this->pick($payload, ['pgTranId']) ?? $this->extractOrderId($payload),
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
        $approved = $this->pick($provision, ['responseCode']) === self::RESPONSE_APPROVED;

        return new VerificationResponse(
            success: $approved,
            paymentId: $this->pick($provision, ['pgTranId']) ?? $this->extractOrderId($payload),
            status: $approved ? 'success' : 'failed',
            raw: ['callback' => $payload, 'provision' => $provision],
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function extractOrderId(array $payload): ?string
    {
        return $this->pick($payload, ['merchantPaymentId']);
    }

    /**
     * İade.
     *
     * Tutar verilirse kısmi iade yapılır; Paratika bunu kendi tarafında
     * `PTREFUND` işlemi olarak kaydeder.
     */
    protected function performRefund(RefundPaymentData $data): RefundResponse
    {
        $request = ['ACTION' => 'REFUND'] + $this->reference($data->paymentId);

        if (($amount = $data->money()) !== null) {
            $request['AMOUNT'] = $amount->toDecimalString();
            $request['CURRENCY'] = strtoupper($data->currency);
        }

        return $this->mapReversal($this->post($request));
    }

    /**
     * Gün sonu öncesi iptal.
     */
    public function cancel(RefundPaymentData $data): RefundResponse
    {
        return $this->mapReversal($this->post(
            ['ACTION' => 'VOID'] + $this->reference($data->paymentId)
        ));
    }

    /**
     * Ön provizyonu kapatır.
     *
     * Paratika kapamayı sanal POS işlem numarasıyla (`PGTRANID`) yapar;
     * bu değer ön provizyon yanıtındaki `pgTranId` alanından gelir.
     * Sipariş numarası da kabul edilir.
     */
    public function capture(CapturePaymentData $data): PaymentResponse
    {
        $request = ['ACTION' => 'POSTAUTH'] + $this->reference(
            (string) ($data->meta('pg_tran_id') ?? $data->orderId)
        );

        if (($amount = $data->money()) !== null) {
            $request['AMOUNT'] = $amount->toDecimalString();
        }

        return $this->mapPaymentResponse($this->post($request), $data->orderId);
    }

    /**
     * İşlem sorgusu.
     *
     * Paratika bir sipariş numarasına ait **tüm** işlemleri döndürür:
     * satış, iade, iptal. Bu yüzden tek bir kayda bakmak yanıltıcıdır —
     * iade edilmiş bir satışın kendi kaydı hâlâ `AP` görünür.
     */
    public function status(string $orderId, array $context = []): StatusResponse
    {
        $response = $this->post([
            'ACTION' => 'QUERYTRANSACTION',
            'MERCHANTPAYMENTID' => $orderId,
        ]);

        $transactions = $response['transactionList'] ?? null;

        if (! is_array($transactions) || $transactions === []) {
            return StatusResponse::notFound($orderId, $response);
        }

        $primary = null;
        $refunded = 0.0;
        $voided = false;

        foreach ($transactions as $transaction) {
            if (! is_array($transaction)) {
                continue;
            }

            $type = strtoupper($this->pick($transaction, ['transactionType']) ?? '');
            $approved = $this->pick($transaction, ['transactionStatus']) === 'AP';

            if (in_array($type, self::PRIMARY_TYPES, true)) {
                // Aynı sipariş için birden çok satış kaydı olabilir
                // (başarısız denemeler); onaylananı tercih ediyoruz.
                if ($primary === null || ($approved && $this->pick($primary, ['transactionStatus']) !== 'AP')) {
                    $primary = $transaction;
                }

                continue;
            }

            if (! $approved) {
                continue;
            }

            if ($type === 'VOID') {
                $voided = true;
            }

            if (in_array($type, ['REFUND', 'PTREFUND'], true)) {
                $amount = $this->pick($transaction, ['amount']);
                $refunded += is_numeric($amount) ? (float) $amount : 0.0;
            }
        }

        if ($primary === null) {
            return StatusResponse::notFound($orderId, $response);
        }

        $currency = strtoupper($this->pick($primary, ['currency']) ?? 'TRY');
        $amount = $this->pick($primary, ['amount']);
        $paid = $amount !== null && is_numeric($amount) ? Money::fromDecimal($amount, $currency) : null;

        return new StatusResponse(
            found: true,
            status: $this->resolveStatus($primary, $voided, $refunded, $paid),
            orderId: $orderId,
            paymentId: $this->pick($primary, ['pgTranId']),
            amount: $paid,
            refundedAmount: $refunded > 0.0 ? Money::fromDecimal($refunded, $currency) : null,
            installment: ($installment = $this->pick($primary, ['installmentCount'])) !== null
                ? (int) $installment
                : null,
            transactionTime: $this->pick($primary, ['timeCreated']),
            maskedCardNumber: $this->maskedCard($primary),
            errorMessage: $this->pick($primary, ['pgTranErrorText', 'errorMsg']),
            raw: $response,
        );
    }

    /**
     * Ana işlemin durumunu iade ve iptal kayıtlarıyla birlikte yorumlar.
     *
     * @param  array<string, mixed>  $primary
     */
    protected function resolveStatus(array $primary, bool $voided, float $refunded, ?Money $paid): string
    {
        $status = OrderStatus::map($this->pick($primary, ['transactionStatus']), OrderStatus::PARATIKA);

        if ($status !== StatusResponse::STATUS_PAID && $status !== StatusResponse::STATUS_PRE_AUTHORIZED) {
            return $status;
        }

        if ($voided) {
            return StatusResponse::STATUS_CANCELLED;
        }

        // Kısmi iade siparişi "iade edildi" yapmaz; ödeme ayakta kalır.
        if ($refunded > 0.0 && $paid instanceof Money
            && Money::fromDecimal($refunded, $paid->currency)->minorUnits >= $paid->minorUnits) {
            return StatusResponse::STATUS_REFUNDED;
        }

        // Ön provizyon henüz tahsil edilmemiştir.
        if (strtoupper($this->pick($primary, ['transactionType']) ?? '') === 'PREAUTH') {
            return StatusResponse::STATUS_PRE_AUTHORIZED;
        }

        return $status;
    }

    /**
     * Siparişe ait tüm işlem kayıtları.
     *
     * @return array<string, mixed>
     */
    public function orderHistory(string $orderId, array $context = []): array
    {
        return $this->post([
            'ACTION' => 'QUERYTRANSACTION',
            'MERCHANTPAYMENTID' => $orderId,
        ]);
    }

    /**
     * BIN sorgusu. Paratika kartın ilk 6 veya 8 hanesini kabul eder.
     */
    public function binLookup(string $bin, array $context = []): BinResponse
    {
        $response = $this->post(['ACTION' => 'QUERYBIN', 'BIN' => $bin]);

        $details = $response['bin'] ?? null;

        if (! is_array($details) || $details === []) {
            return new BinResponse(found: false, raw: $response);
        }

        $type = $this->pick($details, ['cardType']);

        return new BinResponse(
            found: true,
            bankName: $this->pick($details, ['issuer']),
            brand: $this->pick($details, ['cardBrand']),
            // Paratika CREDIT / DEBIT / PREPAID döner.
            type: $type !== null ? strtolower($type) : null,
            commercial: ($level = $this->pick($details, ['cardLevel'])) !== null
                ? strtoupper($level) === 'BUSINESS'
                : null,
            domestic: ($country = $this->pick($details, ['countryIsoA3'])) !== null
                ? strtoupper($country) === 'TUR'
                : null,
            raw: $response,
        );
    }

    /**
     * Taksit seçenekleri.
     *
     * Paratika taksitleri üye işyerinin tanımlı sanal POS'ları bazında
     * döndürür; aynı taksit sayısı birden çok POS'ta bulunabildiği için
     * liste POS adıyla birlikte düzleştirilir.
     *
     * @return list<InstallmentOption>
     */
    public function installmentOptions(Money $amount, ?string $bin = null): array
    {
        $request = ['ACTION' => 'QUERYINSTALLMENT', 'AMOUNT' => $amount->toDecimalString()];

        if ($bin !== null && $bin !== '') {
            $request['BIN'] = $bin;
        }

        $response = $this->post($request);
        $systems = $response['paymentSystemList'] ?? [];

        if (! is_array($systems)) {
            return [];
        }

        $options = [];

        foreach ($systems as $system) {
            if (! is_array($system)) {
                continue;
            }

            $name = $this->pick($system, ['name']);
            $list = $system['installmentList'] ?? [];

            if (! is_array($list)) {
                continue;
            }

            foreach ($list as $row) {
                if (! is_array($row)) {
                    continue;
                }

                $count = $this->pick($row, ['count']);

                // Tek çekim `NOT_ON_US` gibi sayısal olmayan değerlerle
                // de bildiriliyor; taksit seçeneği olarak anlamsızdır.
                if ($count === null || ! ctype_digit($count)) {
                    continue;
                }

                $options[] = new InstallmentOption(
                    count: (int) $count,
                    commissionRate: ($rate = $this->pick($row, ['customerCostCommissionRate'])) !== null
                        ? (float) $rate
                        : null,
                    bankName: $name,
                    raw: $row,
                );
            }
        }

        return $options;
    }

    /**
     * İşlem referansı: sanal POS işlem numarası ya da sipariş numarası.
     *
     * @return array<string, string>
     */
    protected function reference(string $id): array
    {
        // Paratika'nın işlem numaraları sipariş numaralarından farklı bir
        // biçimde (17013MUjC07014059) gelir; ayırt etmek için bunu
        // varsaymak yerine çağıranın verdiği değeri sipariş numarası
        // sayıyoruz. Sanal POS numarası kullanılacaksa açıkça bildirilir.
        return ['MERCHANTPAYMENTID' => $id];
    }

    /**
     * @param  array<string, mixed>  $response
     * @param  array<string, mixed>  $extra
     */
    protected function mapPaymentResponse(array $response, string $orderId, array $extra = []): PaymentResponse
    {
        $approved = $this->pick($response, ['responseCode']) === self::RESPONSE_APPROVED;

        return new PaymentResponse(
            success: $approved,
            paymentId: $this->pick($response, ['pgTranId']) ?? $orderId,
            errorMessage: $approved ? null : ($this->errorMessage($response) ?? 'Ödeme reddedildi.'),
            raw: $response + $extra,
            errorCode: $approved ? null : $this->errorCode($response),
        );
    }

    /**
     * @param  array<string, mixed>  $response
     */
    protected function mapReversal(array $response): RefundResponse
    {
        $approved = $this->pick($response, ['responseCode']) === self::RESPONSE_APPROVED;

        return new RefundResponse(
            success: $approved,
            refundId: $this->pick($response, ['pgTranId']),
            errorMessage: $approved ? null : ($this->errorMessage($response) ?? 'İade reddedildi.'),
            raw: $response,
        );
    }

    /**
     * @param  array<string, mixed>  $response
     */
    protected function errorMessage(array $response): ?string
    {
        return $this->pick($response, ['errorMsg', 'ERROR', 'pgTranErrorText', 'responseMsg']);
    }

    /**
     * @param  array<string, mixed>  $response
     */
    protected function errorCode(array $response): ?string
    {
        return $this->pick($response, ['errorCode', 'ERRORCODE', 'pgTranErrorCode', 'responseCode']);
    }

    /**
     * @param  array<string, mixed>  $transaction
     */
    protected function maskedCard(array $transaction): ?string
    {
        $bin = $this->pick($transaction, ['bin']);
        $last = $this->pick($transaction, ['panLast4']);

        return $bin !== null && $last !== null ? $bin.'******'.$last : null;
    }

    /**
     * Müşteri referansı. Paratika bunu tekil bir değer olarak ister.
     */
    protected function customerId(CreatePaymentData $data): string
    {
        $customer = $data->customer['id'] ?? $data->customer['email'] ?? null;

        return is_scalar($customer) && (string) $customer !== ''
            ? (string) $customer
            : $data->orderId;
    }

    /**
     * Oturum anahtarını uç noktaya ekler.
     */
    protected function tokenUrl(string $endpoint, string $sessionToken): string
    {
        return rtrim($this->config->endpoint($endpoint), '/').'/'.rawurlencode($sessionToken);
    }

    /**
     * Kimlik alanlarını ekleyip form-encoded POST gönderir.
     *
     * @param  array<string, mixed>  $request
     * @return array<string, mixed>
     */
    protected function post(array $request): array
    {
        $this->config->require(['merchantId', 'username', 'password']);

        /** @var array<string, scalar> $fields */
        $fields = $request + [
            'MERCHANT' => $this->config->merchantId,
            'MERCHANTUSER' => $this->config->username,
            'MERCHANTPASSWORD' => $this->config->password,
        ];

        return $this->client->postForm($this->config->endpoint('payment_api'), $fields);
    }
}
