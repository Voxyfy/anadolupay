<?php

declare(strict_types=1);

namespace Voxyfy\AnadoluPay\Gateways\Bank;

use Voxyfy\AnadoluPay\Contracts\SupportsBinQuery;
use Voxyfy\AnadoluPay\Contracts\SupportsCancellation;
use Voxyfy\AnadoluPay\Contracts\SupportsOrderHistory;
use Voxyfy\AnadoluPay\Contracts\SupportsPreAuthorization;
use Voxyfy\AnadoluPay\Contracts\SupportsRecurringPayments;
use Voxyfy\AnadoluPay\Contracts\SupportsStatusQuery;
use Voxyfy\AnadoluPay\DTO\BinResponse;
use Voxyfy\AnadoluPay\DTO\CapturePaymentData;
use Voxyfy\AnadoluPay\DTO\CreatePaymentData;
use Voxyfy\AnadoluPay\DTO\PaymentResponse;
use Voxyfy\AnadoluPay\DTO\RecurringPlan;
use Voxyfy\AnadoluPay\DTO\RefundPaymentData;
use Voxyfy\AnadoluPay\DTO\RefundResponse;
use Voxyfy\AnadoluPay\DTO\StatusResponse;
use Voxyfy\AnadoluPay\DTO\VerificationResponse;
use Voxyfy\AnadoluPay\Exceptions\PaymentFailedException;
use Voxyfy\AnadoluPay\Support\Bank\Currency;
use Voxyfy\AnadoluPay\Support\Bank\OrderStatus;
use Voxyfy\AnadoluPay\Support\Money;

/**
 * Garanti BBVA Sanal POS (GVPS) Driver'ı
 *
 * Protokol özeti:
 *   - Tutarlar kuruş cinsinden tam sayı olarak gönderilir (1,99 TL => 199).
 *   - 3D form imzası: terminalid + orderid + txnamount + txncurrencycode +
 *     successurl + errorurl + txntype + txninstallmentcount + secretKey +
 *     securityData, aralarında ayraç olmadan birleştirilip sha512 (BÜYÜK HARF).
 *   - securityData = sha1(password + terminalId'nin 9 haneye sıfır dolgulusu),
 *     yine BÜYÜK HARF. İptal/iade işlemlerinde `refund_password` kullanılır.
 *   - Provizyon istekleri `GVPSRequest` kök elemanlı XML'dir.
 */
class GarantiGateway extends AbstractBankGateway implements SupportsBinQuery, SupportsCancellation, SupportsOrderHistory, SupportsPreAuthorization, SupportsRecurringPayments, SupportsStatusQuery
{
    /** Bankanın başarılı işlem için döndürdüğü yanıt kodu. */
    protected const SUCCESS_CODE = '00';

    /** Garanti API sürümü. */
    protected const API_VERSION = '512';

    /** Garanti tekrar frekansı kodları; günlük tekrar desteklenmez. */
    protected const RECURRING_FREQUENCIES = [
        RecurringPlan::FREQUENCY_DAY => 'D',
        RecurringPlan::FREQUENCY_WEEK => 'W',
        RecurringPlan::FREQUENCY_MONTH => 'M',
    ];

    /** MotoInd: N => e-ticaret işlemi, Y => mail order. */
    protected const MOTO = 'N';

    /**
     * Bayi kodunun varsayılan yolu. Garanti'nin bayi (B2B) dokümanı alanı
     * `Terminal` düğümünün içinde tanımlar.
     */
    protected const SUB_MERCHANT_FIELD = 'Terminal.SubMerchantID';

    /**
     * @return array<string, scalar>
     */
    protected function build3dFormFields(CreatePaymentData $data): array
    {
        $this->config->require(['merchantId', 'terminalId', 'username', 'password', 'secretKey']);

        $card = $this->requireCard($data);

        $inputs = [
            'secure3dsecuritylevel' => $data->paymentModel === CreatePaymentData::MODEL_3D_PAY ? '3D_PAY' : '3D',
            'mode' => $this->mode(),
            'apiversion' => self::API_VERSION,
            'terminalprovuserid' => $this->config->username,
            'terminaluserid' => $this->config->username,
            'terminalmerchantid' => $this->config->merchantId,
            'terminalid' => $this->config->terminalId,
            'txntype' => $this->txType($data),
            'txnamount' => $this->formatAmount($data->money()),
            'txncurrencycode' => Currency::numeric($data->currency),
            'txninstallmentcount' => $this->formatInstallment($data->installments()),
            'orderid' => $data->orderId,
            'successurl' => $this->successUrl($data),
            'errorurl' => $this->failUrl($data),
            'customeripaddress' => $data->clientIp(),
            'cardnumber' => $card->number,
            'cardexpiredatemonth' => $card->expireMonth,
            'cardexpiredateyear' => $card->expireYearShort(),
            'cardcvv2' => $card->cvv,
        ];

        $inputs['secure3dhash'] = $this->create3dHash($inputs);

        // 3D_PAY'de provizyonu banka form post'undan tamamladığı için bayi
        // kodu burada da gerekiyor. İmzadan sonra ekleniyor: create3dHash()
        // sabit bir alan listesi üzerinden hesaplanıyor.
        if (($subMerchantId = $this->subMerchantId()) !== null) {
            $inputs['submerchantid'] = $subMerchantId;
        }

        return $inputs;
    }

    /**
     * Garanti dönüşte `hashparams` alanında hash'e giren alan adlarını
     * iki nokta ile ayırarak bildirir.
     *
     * @param  array<string, mixed>  $payload
     */
    protected function checkCallbackHash(array $payload): bool
    {
        $incoming = $this->pick($payload, ['hash']);
        $hashParams = $this->pick($payload, ['hashparams']);

        if ($incoming === null || $hashParams === null) {
            return false;
        }

        $values = '';

        foreach (explode(':', $hashParams) as $field) {
            if ($field === '') {
                continue;
            }

            $values .= $this->pick($payload, [$field], '') ?? '';
        }

        return hash_equals(
            $this->upperHash($values.$this->config->secretKey, 'sha512'),
            $incoming,
        );
    }

    /**
     * Garanti'de mdStatus 1-4 arası değerler başarılı doğrulama sayılır
     * (2-4 kart sahibi veya bankanın 3D'ye katılmadığı yarı güvenli akışlardır).
     *
     * @param  array<string, mixed>  $payload
     */
    protected function is3dAuthSuccess(array $payload): bool
    {
        return in_array($this->pick($payload, ['mdstatus']), ['1', '2', '3', '4'], true);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function paymentModelOf(array $payload): string
    {
        return $this->pick($payload, ['secure3dsecuritylevel']) === '3D_PAY'
            ? CreatePaymentData::MODEL_3D_PAY
            : CreatePaymentData::MODEL_3D_SECURE;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    protected function provision(array $payload): array
    {
        $request = [
            'Mode' => $this->mode(),
            'Version' => self::API_VERSION,
            'Terminal' => $this->terminalData(),
            'Customer' => [
                'IPAddress' => $this->pick($payload, ['customeripaddress'], '') ?? '',
            ],
            'Order' => [
                'OrderID' => $this->extractOrderId($payload) ?? '',
            ],
            'Transaction' => [
                'Type' => $this->pick($payload, ['txntype'], 'sales') ?? 'sales',
                'InstallmentCnt' => $this->pick($payload, ['txninstallmentcount'], '') ?? '',
                'Amount' => $this->pick($payload, ['txnamount'], '') ?? '',
                'CurrencyCode' => $this->pick($payload, ['txncurrencycode'], '') ?? '',
                // 13 => 3D Secure ile doğrulanmış işlem
                'CardholderPresentCode' => '13',
                'MotoInd' => self::MOTO,
                'Secure3D' => [
                    'AuthenticationCode' => $this->pick($payload, ['cavv'], '') ?? '',
                    'SecurityLevel' => $this->pick($payload, ['eci'], '') ?? '',
                    'TxnID' => $this->pick($payload, ['xid'], '') ?? '',
                    'Md' => $this->pick($payload, ['md'], '') ?? '',
                ],
            ],
        ];

        $request['Terminal']['HashData'] = $this->createRequestHash($request);

        return $this->postXml($request);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function mapCallbackResponse(array $payload): VerificationResponse
    {
        // 3D Pay: provizyon banka tarafında tamamlandı.
        $approved = $this->pick($payload, ['procreturncode', 'response']) === self::SUCCESS_CODE
            || strtolower($this->pick($payload, ['response'], '') ?? '') === 'approved';

        return new VerificationResponse(
            success: $approved,
            paymentId: $this->pick($payload, ['transid', 'retrefnum']) ?? $this->extractOrderId($payload),
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
        $approved = $this->responseCode($provision) === self::SUCCESS_CODE;

        return new VerificationResponse(
            success: $approved,
            paymentId: $this->refRetNum($provision) ?? $this->extractOrderId($payload),
            status: $approved ? 'success' : 'failed',
            raw: ['callback' => $payload, 'provision' => $provision],
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function extractOrderId(array $payload): ?string
    {
        return $this->pick($payload, ['orderid']);
    }

    /**
     * 3D'siz doğrudan provizyon.
     */
    protected function nonSecurePayment(CreatePaymentData $data): PaymentResponse
    {
        $this->config->require(['merchantId', 'terminalId', 'username', 'password']);

        $card = $this->requireCard($data);

        $request = [
            'Mode' => $this->mode(),
            'Version' => self::API_VERSION,
            'Terminal' => $this->terminalData(),
            'Customer' => ['IPAddress' => $data->clientIp()],
            'Card' => [
                'Number' => $card->number,
                'ExpireDate' => $card->expiry('my'),
                'CVV2' => $card->cvv,
            ],
            'Order' => ['OrderID' => $data->orderId],
            'Transaction' => [
                'Type' => $this->txType($data),
                'InstallmentCnt' => $this->formatInstallment($data->installments()),
                'Amount' => $this->formatAmount($data->money()),
                'CurrencyCode' => Currency::numeric($data->currency),
                'CardholderPresentCode' => '0',
                'MotoInd' => self::MOTO,
            ],
        ];

        if (($recurring = $this->recurringRequest($data)) !== []) {
            $request['Recurring'] = $recurring;
        }

        $request['Terminal']['HashData'] = $this->createRequestHash($request);

        $response = $this->postXml($request);
        $approved = $this->responseCode($response) === self::SUCCESS_CODE;

        return new PaymentResponse(
            success: $approved,
            paymentId: $this->refRetNum($response) ?? $data->orderId,
            errorMessage: $approved ? null : $this->errorMessage($response),
            raw: $response,
            errorCode: $approved ? null : $this->responseCode($response),
        );
    }

    /**
     * İade işlemi.
     *
     * Garanti iadeyi orijinal işlemin `RetrefNum` değeriyle eşler; bu değeri
     * `metadata['ref_ret_num']` ile geçin:
     *
     *     new RefundPaymentData('ORDER-1', 19.90, metadata: ['ref_ret_num' => '...'])
     */
    protected function performRefund(RefundPaymentData $data): RefundResponse
    {
        return $this->reversal($data, 'refund');
    }

    /**
     * Gün sonu öncesi işlem iptali.
     */
    public function cancel(RefundPaymentData $data): RefundResponse
    {
        return $this->reversal($data, 'void');
    }

    /**
     * İade ve iptal aynı istek şemasını paylaşır; yalnızca işlem tipi değişir.
     */
    protected function reversal(RefundPaymentData $data, string $txType): RefundResponse
    {
        $refRetNum = $data->meta('ref_ret_num');

        if (! is_string($refRetNum) || $refRetNum === '') {
            throw new PaymentFailedException(
                message: "Garanti iade/iptal işlemi için metadata['ref_ret_num'] zorunludur.",
                context: ['bank' => $this->config->bank, 'order_id' => $data->paymentId],
            );
        }

        $request = [
            'Mode' => $this->mode(),
            'Version' => self::API_VERSION,
            'Terminal' => $this->terminalData(refund: true),
            'Customer' => ['IPAddress' => (string) ($data->meta('ip') ?? '127.0.0.1')],
            'Order' => ['OrderID' => $data->paymentId],
            'Transaction' => [
                'Type' => $txType,
                'InstallmentCnt' => '',
                // Garanti iptal/iade isteklerinde tutar alanını sabit 1 kuruş bekler.
                'Amount' => $this->formatAmount($data->money() ?? Money::fromMinorUnits(1, $data->currency)),
                'CurrencyCode' => Currency::numeric($data->currency),
                'CardholderPresentCode' => '0',
                'MotoInd' => self::MOTO,
                'OriginalRetrefNum' => $refRetNum,
            ],
        ];

        $request['Terminal']['HashData'] = $this->createRequestHash($request, $txType);

        $response = $this->postXml($request);
        $approved = $this->responseCode($response) === self::SUCCESS_CODE;

        return new RefundResponse(
            success: $approved,
            refundId: $this->refRetNum($response),
            errorMessage: $approved ? null : $this->errorMessage($response),
            raw: $response,
        );
    }

    /**
     * 3D form imzası.
     *
     * @param  array<string, scalar>  $inputs
     */
    protected function create3dHash(array $inputs): string
    {
        $parts = [
            (string) $inputs['terminalid'],
            (string) $inputs['orderid'],
            (string) $inputs['txnamount'],
            (string) $inputs['txncurrencycode'],
            (string) $inputs['successurl'],
            (string) $inputs['errorurl'],
            (string) $inputs['txntype'],
            (string) $inputs['txninstallmentcount'],
            $this->config->secretKey,
            $this->securityData((string) $inputs['txntype']),
        ];

        return $this->upperHash(implode('', $parts), 'sha512');
    }

    /**
     * XML istek imzası (Terminal.HashData).
     *
     * @param  array<string, mixed>  $request
     */
    protected function createRequestHash(array $request, string $txType = 'sales'): string
    {
        $parts = [
            (string) ($request['Order']['OrderID'] ?? ''),
            (string) ($request['Terminal']['ID'] ?? ''),
            (string) ($request['Card']['Number'] ?? ''),
            (string) ($request['Transaction']['Amount'] ?? ''),
            (string) ($request['Transaction']['CurrencyCode'] ?? ''),
            $this->securityData($txType),
        ];

        return $this->upperHash(implode('', $parts), 'sha512');
    }

    /**
     * securityData = sha1(şifre + 9 haneye sıfır dolgulu terminal no).
     *
     * İptal ve iade işlemlerinde normal şifre yerine iade şifresi kullanılır.
     */
    protected function securityData(string $txType): string
    {
        $password = in_array($txType, ['void', 'refund'], true)
            ? $this->config->refundPassword()
            : $this->config->password;

        return $this->upperHash(
            $password.str_pad($this->config->terminalId, 9, '0', STR_PAD_LEFT),
            'sha1',
        );
    }

    /**
     * Garanti tutarları kuruş cinsinden tam sayı olarak bekler.
     */
    protected function formatAmount(Money $money): string
    {
        return $money->toMinorUnitsString();
    }

    /**
     * Tek çekimde taksit alanı boş gönderilir.
     */
    protected function formatInstallment(int $installment): string
    {
        return $installment > 1 ? (string) $installment : '';
    }

    /**
     * @return array<string, string>
     */
    protected function terminalData(bool $refund = false): array
    {
        $user = $refund
            ? (string) ($this->config->extra('refund_username') ?? $this->config->username)
            : $this->config->username;

        return [
            'ProvUserID' => $user,
            'UserID' => $user,
            'HashData' => '',
            'ID' => $this->config->terminalId,
            'MerchantID' => $this->config->merchantId,
        ];
    }

    protected function mode(): string
    {
        return $this->config->testMode ? 'TEST' : 'PROD';
    }

    protected function upperHash(string $value, string $algorithm): string
    {
        return strtoupper(hash($algorithm, $value));
    }

    /**
     * @param  array<string, mixed>  $response
     */
    protected function responseCode(array $response): ?string
    {
        $code = $response['Transaction']['Response']['Code'] ?? null;

        return is_scalar($code) ? (string) $code : null;
    }

    /**
     * @param  array<string, mixed>  $response
     */
    protected function refRetNum(array $response): ?string
    {
        $value = $response['Transaction']['RetrefNum'] ?? null;

        return is_scalar($value) && (string) $value !== '' ? (string) $value : null;
    }

    /**
     * @param  array<string, mixed>  $response
     */
    protected function errorMessage(array $response): ?string
    {
        $transaction = $response['Transaction'] ?? [];

        foreach (['ErrorMsg', 'SysErrMsg', 'Message'] as $field) {
            $value = $transaction['Response'][$field] ?? null;

            if (is_scalar($value) && (string) $value !== '') {
                return (string) $value;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $request
     * @return array<string, mixed>
     */
    protected function postXml(array $request): array
    {
        return $this->client->postXml(
            url: $this->config->endpoint('payment_api'),
            data: $this->withSubMerchant($request),
            root: 'GVPSRequest',
        );
    }

    /**
     * Bayi (alt üye işyeri) kodu; tanımlı değilse null.
     */
    protected function subMerchantId(): ?string
    {
        $value = $this->config->extra('sub_merchant_id');

        return is_scalar($value) && (string) $value !== '' ? (string) $value : null;
    }

    /**
     * Bayi terminallerinde Garanti her finansal istekte bayi kodunu zorunlu
     * tutar; alan gitmezse işlem 0809 ("SubMerchantID alanında bayii kodunun
     * gönderilmesi zorunludur") ile reddedilir.
     *
     * Enjeksiyon tek noktadan, bütün XML isteklerinin geçtiği `postXml()`
     * üzerinden yapılıyor: alan `createRequestHash()`'in okuduğu sabit listeye
     * girmediği için imza hesaplandıktan sonra eklenmesi güvenli. Böylece
     * provizyon, postauth, iptal/iade ve sorgu istekleri tek yerden kapsanıyor.
     *
     * Düğümün yeri bankanın bayi tanımına göre değişebildiği için
     * `sub_merchant_id_path` ile taşınabilir: nokta ile ayrılmış yol
     * (örn. `Terminal.SubMerchantID`) alanı iç düğüme yazar.
     *
     * @param  array<string, mixed>  $request
     * @return array<string, mixed>
     */
    protected function withSubMerchant(array $request): array
    {
        if (($subMerchantId = $this->subMerchantId()) === null) {
            return $request;
        }

        $path = $this->config->extra('sub_merchant_id_path');
        $path = is_string($path) && trim($path) !== '' ? trim($path) : self::SUB_MERCHANT_FIELD;

        $keys = explode('.', $path);
        $field = (string) array_pop($keys);
        $target = &$request;

        foreach ($keys as $key) {
            if (! isset($target[$key]) || ! is_array($target[$key])) {
                $target[$key] = [];
            }

            $target = &$target[$key];
        }

        $target[$field] = $subMerchantId;

        return $request;
    }

    /**
     * Sipariş durumunu sorgular (`orderinq`).
     *
     * Garanti sorgu isteğinde de tutar alanını zorunlu tutar; sabit
     * 1 kuruş gönderilir ve yanıttaki gerçek tutar okunur.
     */
    public function status(string $orderId, array $context = []): StatusResponse
    {
        $request = [
            'Mode' => $this->mode(),
            'Version' => self::API_VERSION,
            'Terminal' => $this->terminalData(),
            'Customer' => ['IPAddress' => (string) ($context['ip'] ?? '127.0.0.1')],
            'Order' => ['OrderID' => $orderId],
            'Transaction' => [
                'Type' => 'orderinq',
                'InstallmentCnt' => '',
                'Amount' => $this->formatAmount(Money::fromMinorUnits(1)),
                'CurrencyCode' => Currency::numeric((string) ($context['currency'] ?? 'TRY')),
                'CardholderPresentCode' => '0',
                'MotoInd' => self::MOTO,
            ],
        ];

        $request['Terminal']['HashData'] = $this->createRequestHash($request, 'orderinq');

        $response = $this->postXml($request);

        if ($this->responseCode($response) !== self::SUCCESS_CODE) {
            return StatusResponse::notFound($orderId, $response);
        }

        $inquiry = $response['Order']['OrderInqResult'] ?? [];
        $inquiry = is_array($inquiry) ? $inquiry : [];

        $authAmount = $this->pick($inquiry, ['AuthAmount']);
        $preAuthAmount = $this->pick($inquiry, ['PreAuthAmount']);

        return new StatusResponse(
            found: true,
            status: OrderStatus::map($this->pick($inquiry, ['Status']), OrderStatus::GARANTI),
            orderId: $orderId,
            paymentId: $this->pick($inquiry, ['RetrefNum', 'AuthCode']),
            // Garanti tutarları kuruş cinsinden döndürür.
            amount: $this->minorUnitsToMoney($authAmount !== null && $authAmount !== '0' ? $authAmount : $preAuthAmount),
            installment: $this->pick($inquiry, ['InstallmentCnt']) !== null
                ? (int) $this->pick($inquiry, ['InstallmentCnt'])
                : null,
            transactionTime: $this->pick($inquiry, ['ProvDate', 'PreAuthDate', 'AuthDate']),
            maskedCardNumber: $this->pick($inquiry, ['CardNumberMasked']),
            raw: $response,
        );
    }

    /**
     * Kuruş cinsinden gelen bir tutarı `Money`ye çevirir.
     */
    protected function minorUnitsToMoney(?string $amount): ?Money
    {
        return $amount === null || $amount === '' || ! is_numeric($amount)
            ? null
            : Money::fromMinorUnits((int) $amount);
    }

    /**
     * İşlem tipi: normal satış `sales`, ön provizyon `preauth`.
     */
    protected function txType(CreatePaymentData $data): string
    {
        return $data->preAuthorization ? 'preauth' : 'sales';
    }

    /**
     * Ön provizyonu kapatır (`postauth`).
     *
     * Garanti kapamayı orijinal işlemin `RetrefNum` değeriyle eşler;
     * `metadata['ref_ret_num']` zorunludur.
     */
    public function capture(CapturePaymentData $data): PaymentResponse
    {
        $refRetNum = $data->meta('ref_ret_num');

        if (! is_string($refRetNum) || $refRetNum === '') {
            throw new PaymentFailedException(
                message: "Garanti provizyon kapama için metadata['ref_ret_num'] zorunludur.",
                context: ['bank' => $this->config->bank, 'order_id' => $data->orderId],
            );
        }

        $request = [
            'Mode' => $this->mode(),
            'Version' => self::API_VERSION,
            'Terminal' => $this->terminalData(),
            'Customer' => ['IPAddress' => $data->clientIp()],
            'Order' => ['OrderID' => $data->orderId],
            'Transaction' => [
                'Type' => 'postauth',
                'Amount' => $this->formatAmount($data->money() ?? Money::fromMinorUnits(1, $data->currency)),
                'CurrencyCode' => Currency::numeric($data->currency),
                'OriginalRetrefNum' => $refRetNum,
            ],
        ];

        $request['Terminal']['HashData'] = $this->createRequestHash($request, 'postauth');

        $response = $this->postXml($request);
        $approved = $this->responseCode($response) === self::SUCCESS_CODE;

        return new PaymentResponse(
            success: $approved,
            paymentId: $this->refRetNum($response) ?? $data->orderId,
            errorMessage: $approved ? null : $this->errorMessage($response),
            raw: $response,
            errorCode: $approved ? null : $this->responseCode($response),
        );
    }

    /**
     * BIN sorgusu (`bininq`).
     */
    public function binLookup(string $bin, array $context = []): BinResponse
    {
        $request = [
            'Mode' => $this->mode(),
            'Version' => 'v0.1',
            'Terminal' => $this->terminalData(),
            'Customer' => ['IPAddress' => (string) ($context['ip'] ?? '127.0.0.1')],
            'Order' => ['OrderID' => $this->randomString(16)],
            'Transaction' => [
                'Type' => 'bininq',
                'Amount' => $this->formatAmount(Money::fromMinorUnits(1)),
                'BINInq' => [
                    // A: tüm gruplar, A: tüm kart tipleri
                    'Group' => 'A',
                    'CardType' => 'A',
                    'BINNum' => $bin,
                ],
            ],
        ];

        $request['Terminal']['HashData'] = $this->createRequestHash($request, 'bininq');

        $response = $this->postXml($request);

        if ($this->responseCode($response) !== self::SUCCESS_CODE) {
            return BinResponse::notFound($response);
        }

        $card = $response['Transaction']['BINList']['BINInfo'] ?? [];
        $card = is_array($card) ? (array_is_list($card) ? (array) ($card[0] ?? []) : $card) : [];

        return new BinResponse(
            found: $card !== [],
            bankName: $this->pick($card, ['BankName', 'Bank']),
            brand: strtolower((string) ($this->pick($card, ['CardBrand', 'Brand']) ?? '')) ?: null,
            type: match (strtoupper((string) ($this->pick($card, ['CardType']) ?? ''))) {
                'C', 'CREDIT' => 'credit',
                'D', 'DEBIT' => 'debit',
                default => null,
            },
            raw: $response,
        );
    }

    /**
     * Siparişin hareket dökümü (`orderhistoryinq`).
     *
     * @return array<string, mixed>
     */
    public function orderHistory(string $orderId, array $context = []): array
    {
        $request = [
            'Mode' => $this->mode(),
            'Version' => self::API_VERSION,
            'Terminal' => $this->terminalData(),
            'Customer' => ['IPAddress' => (string) ($context['ip'] ?? '127.0.0.1')],
            'Order' => ['OrderID' => $orderId],
            'Transaction' => [
                'Type' => 'orderhistoryinq',
                'InstallmentCnt' => '',
                'Amount' => $this->formatAmount(Money::fromMinorUnits(1)),
                'CurrencyCode' => Currency::numeric((string) ($context['currency'] ?? 'TRY')),
                'CardholderPresentCode' => '0',
                'MotoInd' => self::MOTO,
            ],
        ];

        $request['Terminal']['HashData'] = $this->createRequestHash($request, 'orderhistoryinq');

        return $this->postXml($request);
    }

    /**
     * Garanti tekrar frekansları (yıllık desteklenmez).
     *
     * @return list<string>
     */
    public function supportedRecurringFrequencies(): array
    {
        return array_keys(self::RECURRING_FREQUENCIES);
    }

    /**
     * Tekrarlayan ödeme bloğu (`Recurring`).
     *
     * @return array<string, string>
     */
    protected function recurringRequest(CreatePaymentData $data): array
    {
        $plan = $this->recurringPlan($data);

        if ($plan === null) {
            return [];
        }

        return [
            'TotalPaymentNum' => (string) $plan->paymentCount,
            'FrequencyType' => $plan->frequencyCode(self::RECURRING_FREQUENCIES),
            'FrequencyInterval' => (string) $plan->interval,
            // R: sabit tutarlı, G: değişken tutarlı
            'Type' => 'R',
            'StartDate' => $plan->startDate?->format('Ymd') ?? '',
        ];
    }
}
