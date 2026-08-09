<?php

declare(strict_types=1);

namespace Voxyfy\AnadoluPay\Gateways\Bank;

use Voxyfy\AnadoluPay\Contracts\SupportsCancellation;
use Voxyfy\AnadoluPay\Contracts\SupportsOrderHistory;
use Voxyfy\AnadoluPay\Contracts\SupportsPreAuthorization;
use Voxyfy\AnadoluPay\Contracts\SupportsRecurringPayments;
use Voxyfy\AnadoluPay\Contracts\SupportsStatusQuery;
use Voxyfy\AnadoluPay\DTO\CapturePaymentData;
use Voxyfy\AnadoluPay\DTO\CreatePaymentData;
use Voxyfy\AnadoluPay\DTO\PaymentResponse;
use Voxyfy\AnadoluPay\DTO\RecurringPlan;
use Voxyfy\AnadoluPay\DTO\RefundPaymentData;
use Voxyfy\AnadoluPay\DTO\RefundResponse;
use Voxyfy\AnadoluPay\DTO\StatusResponse;
use Voxyfy\AnadoluPay\DTO\VerificationResponse;
use Voxyfy\AnadoluPay\Support\Bank\Currency;
use Voxyfy\AnadoluPay\Support\Bank\OrderStatus;
use Voxyfy\AnadoluPay\Support\Money;

/**
 * Asseco / Payten (NestPay, eski adıyla EST) Sanal POS Driver'ı
 *
 * Türkiye'de en yaygın sanal POS altyapısıdır. Aynı protokolü kullanan bankalar:
 * Akbank, İş Bankası, Ziraat Bankası, Halkbank, QNB Finansbank, TEB,
 * Şekerbank, Anadolubank ve ING.
 *
 * Protokol özeti:
 *   - 3D formu `hashAlgorithm=ver3` ile imzalanır: alanlar doğal sırada
 *     sıralanır, `hash`/`encoding` alanları çıkarılır, sona secret key
 *     eklenir, değerler `|` ile birleştirilir ve sha512 + base64 alınır.
 *   - Provizyon/iade/iptal istekleri `CC5Request` kök elemanlı XML'dir.
 *   - Başarı kriteri `ProcReturnCode === '00'`.
 */
class AssecoGateway extends AbstractBankGateway implements SupportsCancellation, SupportsOrderHistory, SupportsPreAuthorization, SupportsRecurringPayments, SupportsStatusQuery
{
    /** Bankanın başarılı işlem için döndürdüğü prosedür kodu. */
    protected const SUCCESS_CODE = '00';

    /** NestPay tekrar frekansı kodları. */
    protected const RECURRING_FREQUENCIES = [
        RecurringPlan::FREQUENCY_DAY => 'D',
        RecurringPlan::FREQUENCY_WEEK => 'W',
        RecurringPlan::FREQUENCY_MONTH => 'M',
        RecurringPlan::FREQUENCY_YEAR => 'Y',
    ];

    /** Hash hesabından her zaman çıkarılan alanlar. */
    protected const HASH_EXCLUDED_FIELDS = ['hash', 'encoding', 'nationalidno'];

    /**
     * @return array<string, scalar>
     */
    protected function build3dFormFields(CreatePaymentData $data): array
    {
        $this->config->require(['merchantId', 'secretKey']);

        $inputs = [
            'hashAlgorithm' => 'ver3',
            'clientid' => $this->config->merchantId,
            'storetype' => $this->storeType($data->paymentModel),
            'amount' => $this->formatAmount($data->money()),
            'oid' => $data->orderId,
            'okUrl' => $this->successUrl($data),
            'failUrl' => $this->failUrl($data),
            'rnd' => $this->randomString(),
            'lang' => $data->lang !== '' ? $data->lang : $this->config->lang,
            'currency' => Currency::numeric($data->currency),
            'taksit' => $this->formatInstallment($data->installments()),
            'TranType' => $this->txType($data),
        ];

        // 3D Host modelinde kart bilgileri banka sayfasında toplanır.
        if ($data->paymentModel !== CreatePaymentData::MODEL_3D_HOST) {
            $card = $this->requireCard($data);

            $inputs['pan'] = $card->number;
            $inputs['Ecom_Payment_Card_ExpDate_Month'] = $card->expireMonth;
            $inputs['Ecom_Payment_Card_ExpDate_Year'] = $card->expireYearShort();
            $inputs['cv2'] = $card->cvv;
        }

        // NestPay tekrarlayan ödeme planını 3D formunda değil provizyon
        // isteğinde taşır; plan varlığı burada yalnızca doğrulanır.
        $this->recurringPlan($data);

        $inputs['hash'] = $this->createHash($inputs);

        return $inputs;
    }

    /**
     * NestPay tek bir 3D geçidi kullanır; model `storetype` alanıyla belirlenir.
     * 3D Host için ayrı bir uç tanımlıysa o kullanılır.
     */
    protected function gateway3dUrl(CreatePaymentData $data): string
    {
        if ($data->paymentModel === CreatePaymentData::MODEL_3D_HOST
            && isset($this->config->endpoints['gateway_3d_host'])) {
            return $this->config->endpoint('gateway_3d_host');
        }

        return $this->config->endpoint('gateway_3d');
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function checkCallbackHash(array $payload): bool
    {
        $incoming = $this->pick($payload, ['HASH']);

        if ($incoming === null) {
            return false;
        }

        return hash_equals($this->createHash($payload), $incoming);
    }

    /**
     * NestPay'de yalnızca `mdStatus === '1'` tam 3D doğrulaması sayılır.
     *
     * @param  array<string, mixed>  $payload
     */
    protected function is3dAuthSuccess(array $payload): bool
    {
        return $this->pick($payload, ['mdStatus']) === '1';
    }

    /**
     * Banka `storetype` alanını dönüşte aynen geri gönderir; ödeme modelini
     * buradan okuyabiliyoruz.
     *
     * @param  array<string, mixed>  $payload
     */
    protected function paymentModelOf(array $payload): string
    {
        return match ($this->pick($payload, ['storetype'])) {
            '3d_pay', '3d_pay_hosting' => CreatePaymentData::MODEL_3D_PAY,
            '3d_host' => CreatePaymentData::MODEL_3D_HOST,
            default => CreatePaymentData::MODEL_3D_SECURE,
        };
    }

    /**
     * 3D doğrulaması sonrası provizyon isteği (CC5Request).
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    protected function provision(array $payload): array
    {
        $request = $this->accountData() + [
            'Type' => 'Auth',
            'IPAddress' => $this->pick($payload, ['clientIp', 'ip'], '') ?? '',
            'OrderId' => $this->extractOrderId($payload) ?? '',
            'Total' => $this->pick($payload, ['amount'], '') ?? '',
            'Currency' => $this->pick($payload, ['currency'], '') ?? '',
            'Taksit' => $this->pick($payload, ['taksit'], '') ?? '',
            'Number' => $this->pick($payload, ['md'], '') ?? '',
            'PayerTxnId' => $this->pick($payload, ['xid'], '') ?? '',
            'PayerSecurityLevel' => $this->pick($payload, ['eci'], '') ?? '',
            'PayerAuthenticationCode' => $this->pick($payload, ['cavv'], '') ?? '',
            'Mode' => $this->mode(),
        ];

        return $this->postXml($request);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function mapCallbackResponse(array $payload): VerificationResponse
    {
        // 3D Pay / 3D Host: provizyon banka tarafında yapıldı, sonuç dönüşte.
        $approved = $this->pick($payload, ['ProcReturnCode']) === self::SUCCESS_CODE;

        return new VerificationResponse(
            success: $approved,
            paymentId: $this->pick($payload, ['TransId']) ?? $this->extractOrderId($payload),
            status: $approved ? 'success' : 'failed',
            raw: ['callback' => $payload],
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $provision
     */
    protected function mapProvisionResponse(array $payload, array $provision): VerificationResponse
    {
        $approved = $this->pick($provision, ['ProcReturnCode']) === self::SUCCESS_CODE;

        return new VerificationResponse(
            success: $approved,
            paymentId: $this->pick($provision, ['TransId']) ?? $this->extractOrderId($payload),
            status: $approved ? 'success' : 'failed',
            raw: ['callback' => $payload, 'provision' => $provision],
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function extractOrderId(array $payload): ?string
    {
        return $this->pick($payload, ['oid', 'OrderId', 'ReturnOid']);
    }

    /**
     * 3D'siz doğrudan provizyon.
     */
    protected function nonSecurePayment(CreatePaymentData $data): PaymentResponse
    {
        $card = $this->requireCard($data);

        $response = $this->postXml($this->accountData() + [
            'Type' => $this->txType($data),
            'IPAddress' => $data->clientIp(),
            'OrderId' => $data->orderId,
            'Total' => $this->formatAmount($data->money()),
            'Currency' => Currency::numeric($data->currency),
            'Taksit' => $this->formatInstallment($data->installments()),
            'Number' => $card->number,
            'Expires' => $card->expiry('m/y'),
            'Cvv2Val' => $card->cvv,
            'Mode' => $this->mode(),
        ] + $this->recurringRequest($data));

        $approved = $this->pick($response, ['ProcReturnCode']) === self::SUCCESS_CODE;

        return new PaymentResponse(
            success: $approved,
            paymentId: $this->pick($response, ['TransId']) ?? $data->orderId,
            errorMessage: $approved ? null : $this->pick($response, ['ErrMsg']),
            raw: $response,
            errorCode: $approved ? null : $this->pick($response, ['ProcReturnCode']),
        );
    }

    /**
     * Tam veya kısmi iade. Tutar verilmezse banka tam iade uygular.
     */
    protected function performRefund(RefundPaymentData $data): RefundResponse
    {
        $request = $this->accountData() + [
            'Type' => 'Credit',
            'OrderId' => $data->paymentId,
        ];

        if (($amount = $data->money()) !== null) {
            $request['Total'] = $this->formatAmount($amount);
        }

        $response = $this->postXml($request);
        $approved = $this->pick($response, ['ProcReturnCode']) === self::SUCCESS_CODE;

        return new RefundResponse(
            success: $approved,
            refundId: $this->pick($response, ['TransId']),
            errorMessage: $approved ? null : $this->pick($response, ['ErrMsg']),
            raw: $response,
        );
    }

    /**
     * Gün sonu öncesi işlem iptali.
     */
    public function cancel(RefundPaymentData $data): RefundResponse
    {
        $response = $this->postXml($this->accountData() + [
            'Type' => 'Void',
            'OrderId' => $data->paymentId,
        ]);

        $approved = $this->pick($response, ['ProcReturnCode']) === self::SUCCESS_CODE;

        return new RefundResponse(
            success: $approved,
            refundId: $this->pick($response, ['TransId']),
            errorMessage: $approved ? null : $this->pick($response, ['ErrMsg']),
            raw: $response,
        );
    }

    /**
     * Sipariş durumunu sorgular.
     *
     * NestPay durumu `Extra.ORDERSTATUS` alanında tek harfle bildirir.
     */
    public function status(string $orderId, array $context = []): StatusResponse
    {
        $response = $this->postXml($this->accountData() + [
            'OrderId' => $orderId,
            'Extra' => ['ORDERSTATUS' => 'QUERY'],
        ]);

        $extra = is_array($response['Extra'] ?? null) ? $response['Extra'] : [];

        // Sorgu yanıtında `ORDERSTATUS` tek harflik bir durum kodu değil,
        // birleşik bir alandır: "ORD_ID:… TRANS_STAT:… AUTH_CODE:…".
        // Çözümlenen alanlar `Extra`ya katılır; böylece aşağıdaki aramalar
        // hem birleşik hem düz gelen kurulumlarda çalışır.
        $raw = $this->pick($extra, ['ORDERSTATUS']);
        $extra += $this->parseOrderStatus($raw);

        // Alanın birleşik olup olmadığını içeriğine göre değil biçimine göre
        // ayırmak gerekir: sipariş bulunamadığında birleşik alan gelir ama
        // içi boştur. Ham dizgiye düşülürse o boş şablon durum kodu sanılır.
        $compound = $raw !== null && str_contains($raw, ':');

        $orderStatus = $compound
            ? $this->pick($extra, ['TRANS_STAT', 'ORD_STAT'])
            : ($raw ?? $this->pick($extra, ['ORD_STAT', 'TRANS_STAT']));

        // Sipariş yoksa banka tüm alanları boş döndürür; `ORDERSTATUS`
        // alanı dolu görünse bile içi boştur.
        if ($orderStatus === null) {
            return StatusResponse::notFound($orderId, $response);
        }

        return new StatusResponse(
            found: true,
            status: OrderStatus::map($orderStatus, OrderStatus::NESTPAY),
            orderId: $orderId,
            paymentId: $this->pick($response, ['TransId']),
            // Birleşik alandaki tutarlar **kuruş** cinsindendir; düz gelen
            // `Extra.ORIG_TRANS_AMT` ise ondalıktır. Gerçek terminalde
            // 100,00 TL'lik ödeme `ORIG_TRANS_AMT:10000` olarak döndü —
            // ondalık sayılırsa tutar 100 kat büyük raporlanır.
            amount: $compound
                ? $this->parseMinorAmount($this->pick($extra, ['ORIG_TRANS_AMT', 'CAPTURE_AMT']))
                : $this->parseAmount($this->pick($extra, ['ORIG_TRANS_AMT'])),
            transactionTime: $this->pick($extra, ['TRXDATE', 'AUTH_DTTM']),
            maskedCardNumber: $this->pick($extra, ['NUMCODE']) === null
                ? $this->pick($response, ['MaskedPan'])
                : null,
            errorMessage: $this->pick($response, ['ErrMsg']),
            raw: $response,
        );
    }

    /**
     * Kuruş cinsinden gelen tutarı `Money`ye çevirir.
     */
    protected function parseMinorAmount(?string $value): ?Money
    {
        return $value !== null && is_numeric($value)
            ? Money::fromMinorUnits((int) $value)
            : null;
    }

    /**
     * Birleşik `ORDERSTATUS` alanını ayrıştırır.
     *
     * Banka şu biçimde döndürür; değerler boşluk içerebildiği için
     * (tarihler) ayrıştırma bilinen anahtarlara göre yapılır:
     *
     *     ORD_ID:ZR-1 CHARGE_TYPE_CD:S ORIG_TRANS_AMT:1.00 TRANS_STAT:A …
     *
     * Değerlerin tamamı boşsa dizi boş döner — sipariş bulunamamış demektir.
     *
     * @return array<string, string>
     */
    protected function parseOrderStatus(?string $value): array
    {
        $keys = [
            'ORD_ID', 'CHARGE_TYPE_CD', 'ORIG_TRANS_AMT', 'CAPTURE_AMT',
            'TRANS_STAT', 'AUTH_DTTM', 'CAPTURE_DTTM', 'AUTH_CODE',
        ];

        if ($value === null || ! str_contains($value, ':')) {
            return [];
        }

        $pattern = '/(?P<key>'.implode('|', $keys).'):(?P<value>.*?)(?=\s+(?:'.implode('|', $keys).'):|$)/s';

        if (preg_match_all($pattern, $value, $matches, PREG_SET_ORDER) === 0) {
            return [];
        }

        $parsed = [];

        foreach ($matches as $match) {
            $field = trim($match['value']);

            if ($field !== '') {
                $parsed[$match['key']] = $field;
            }
        }

        return $parsed;
    }

    /**
     * NestPay tutarı kuruşsuz ondalıklı dizgi olarak döndürür.
     */
    protected function parseAmount(?string $amount): ?Money
    {
        return $amount === null || $amount === '' ? null : Money::fromDecimal($amount);
    }

    /**
     * NestPay `hashAlgorithm=ver3` imzası.
     *
     * Alanlar doğal sırada (büyük/küçük harf duyarsız) sıralanır,
     * `hash`/`encoding`/`nationalidno` çıkarılır, sona secret key eklenir,
     * `|` ve `\` karakterleri kaçırılır ve sha512 + base64 alınır.
     *
     * @param  array<string, mixed>  $fields
     */
    protected function createHash(array $fields): string
    {
        $values = [];

        foreach ($fields as $key => $value) {
            if (in_array(strtolower((string) $key), self::HASH_EXCLUDED_FIELDS, true)) {
                continue;
            }

            // `null` boş dizgi sayılır, atlanmaz. Banka boş alanları hash'e
            // boş dizgi olarak katar; Laravel'in `ConvertEmptyStringsToNull`
            // middleware'i ise dönüş yükündeki boş alanları `null` yapar.
            // Atlanırsa o alanlar hash'ten düşer ve **her** 3D dönüşü imza
            // hatası verir.
            if ($value === null) {
                $values[(string) $key] = '';

                continue;
            }

            if (! is_scalar($value)) {
                continue;
            }

            $values[(string) $key] = (string) $value;
        }

        ksort($values, SORT_NATURAL | SORT_FLAG_CASE);

        $values[] = $this->config->secretKey;

        $escaped = array_map(
            static fn (string $value): string => str_replace(
                ['\\', '|'],
                ['\\\\', '\\|'],
                $value,
            ),
            array_values($values),
        );

        return base64_encode(hash('sha512', implode('|', $escaped), true));
    }

    /**
     * NestPay tek çekimde taksit alanını boş bekler.
     */
    /**
     * NestPay işlem modu: `P` canlı, `T` test.
     *
     * `T` gönderildiğinde işlem bankaya ulaşır ama finansal kayda geçmez.
     * Test terminallerinde iki değer de kabul edilir; ayrımı yapmak, canlı
     * terminale `test_mode` ile bağlananın gerçekten tahsilat yapmamasını
     * sağlar.
     */
    protected function mode(): string
    {
        return $this->config->testMode ? 'T' : 'P';
    }

    protected function formatInstallment(int $installment): string
    {
        return $installment > 1 ? (string) $installment : '';
    }

    /**
     * NestPay tutarı sabit ondalık yerine doğal gösterimle bekler
     * (100 => "100", 1.99 => "1.99"). Hash gövdeye birebir aynı dizgiden
     * hesaplandığı için bu biçimin korunması kritiktir.
     */
    protected function formatAmount(Money $money): string
    {
        return $money->toNaturalString();
    }

    /**
     * Ödeme modelini bankanın `storetype` değerine çevirir.
     */
    protected function storeType(string $paymentModel): string
    {
        return match ($paymentModel) {
            CreatePaymentData::MODEL_3D_PAY => '3d_pay',
            CreatePaymentData::MODEL_3D_HOST => '3d_host',
            CreatePaymentData::MODEL_NON_SECURE => 'regular',
            default => '3d',
        };
    }

    /**
     * Her XML isteğinde tekrar eden kimlik alanları.
     *
     * @return array<string, string>
     */
    protected function accountData(): array
    {
        $this->config->require(['merchantId', 'username', 'password']);

        return [
            'Name' => $this->config->username,
            'Password' => $this->config->password,
            'ClientId' => $this->config->merchantId,
        ];
    }

    /**
     * NestPay XML API'sine istek gönderir.
     *
     * @param  array<string, mixed>  $request
     * @return array<string, mixed>
     */
    protected function postXml(array $request): array
    {
        return $this->client->postXml(
            url: $this->config->endpoint('payment_api'),
            data: $request,
            root: 'CC5Request',
            encoding: 'ISO-8859-9',
        );
    }

    /**
     * İşlem tipi: normal satış `Auth`, ön provizyon `PreAuth`.
     */
    protected function txType(CreatePaymentData $data): string
    {
        return $data->preAuthorization ? 'PreAuth' : 'Auth';
    }

    /**
     * Ön provizyonu kapatır (`PostAuth`).
     *
     * Kapama tutarı ön provizyondan küçükse NestPay farkı `Extra.PREAMT`
     * alanında ister.
     */
    public function capture(CapturePaymentData $data): PaymentResponse
    {
        $request = $this->accountData() + [
            'Type' => 'PostAuth',
            'OrderId' => $data->orderId,
        ];

        if (($amount = $data->money()) !== null) {
            $request['Total'] = $this->formatAmount($amount);

            if (($preAuth = $data->meta('pre_auth_amount')) !== null) {
                $request['Extra'] = ['PREAMT' => $this->formatAmount(Money::of($preAuth, $data->currency))];
            }
        }

        $response = $this->postXml($request);
        $approved = $this->pick($response, ['ProcReturnCode']) === self::SUCCESS_CODE;

        return new PaymentResponse(
            success: $approved,
            paymentId: $this->pick($response, ['TransId']) ?? $data->orderId,
            errorMessage: $approved ? null : $this->pick($response, ['ErrMsg']),
            raw: $response,
            errorCode: $approved ? null : $this->pick($response, ['ProcReturnCode']),
        );
    }

    /**
     * Siparişin hareket dökümü (`Extra.ORDERHISTORY`).
     *
     * @return array<string, mixed>
     */
    public function orderHistory(string $orderId, array $context = []): array
    {
        return $this->postXml($this->accountData() + [
            'OrderId' => $orderId,
            'Extra' => ['ORDERHISTORY' => 'QUERY'],
        ]);
    }

    /**
     * NestPay tekrar frekansları.
     *
     * @return list<string>
     */
    public function supportedRecurringFrequencies(): array
    {
        return array_keys(self::RECURRING_FREQUENCIES);
    }

    /**
     * Tekrarlayan ödeme bloğu (`PbOrder`).
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
            'PbOrder' => [
                // 0: taksitsiz varsayılan sipariş tipi
                'OrderType' => '0',
                'OrderFrequencyInterval' => (string) $plan->interval,
                'OrderFrequencyCycle' => $plan->frequencyCode(self::RECURRING_FREQUENCIES),
                'TotalNumberPayments' => (string) $plan->paymentCount,
            ],
        ];
    }
}
