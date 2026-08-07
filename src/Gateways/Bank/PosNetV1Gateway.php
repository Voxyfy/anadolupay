<?php

declare(strict_types=1);

namespace Voxyfy\AnadoluPay\Gateways\Bank;

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
use Voxyfy\AnadoluPay\Exceptions\PaymentFailedException;
use Voxyfy\AnadoluPay\Support\Money;

/**
 * Albaraka Türk PosNet V1 (JSON API) Driver'ı
 *
 * PosNet'in JSON tabanlı yeni sürümüdür. Yapı Kredi'nin XML tabanlı
 * PosNet'inden farklı olarak paket (data1/data2/sign) mekanizması yoktur.
 *
 * Protokol özeti:
 *   - Her istek `MACParams` alanında MAC hesabına giren alan adlarını
 *     iki nokta ile ayırarak bildirir.
 *   - MAC = sha256_b64( ilgili alan değerleri + secretKey ), ayraçsız.
 *   - Para birimi alfabetik kısaltmayla gönderilir (TRY => TL).
 *   - İşlem tipi hem gövdede hem de API yolunda kullanılır
 *     (örn. `.../Sale`, `.../Return`).
 */
class PosNetV1Gateway extends AbstractBankGateway implements SupportsCancellation, SupportsPreAuthorization, SupportsStatusQuery
{
    /** Başarılı işlem kodu. */
    protected const SUCCESS_CODE = '00';

    /** API sürümü. */
    protected const API_VERSION = 'V100';

    /** Sipariş numarasının sabit uzunluğu. */
    protected const ORDER_ID_LENGTH = 20;

    /** İade/iptal/durum sorgularında kullanılan uzunluk. */
    protected const ORDER_ID_TOTAL_LENGTH = 24;

    /** 3D Secure ile alınan siparişlerin ön eki. */
    protected const ORDER_ID_3D_PREFIX = 'TDS_';

    /** PosNet V1 para birimlerini alfabetik kısaltmayla bekler. */
    protected const CURRENCIES = [
        'TRY' => 'TL',
        'USD' => 'US',
        'EUR' => 'EU',
        'GBP' => 'GB',
        'JPY' => 'JP',
        'RUB' => 'RU',
    ];

    /**
     * @return array<string, mixed>
     */
    protected function build3dFormFields(CreatePaymentData $data): array
    {
        $this->config->require(['merchantId', 'terminalId', 'secretKey']);

        $inputs = [
            'MerchantNo' => $this->config->merchantId,
            'TerminalNo' => $this->config->terminalId,
            'PosnetID' => $this->posNetId(),
            'TransactionType' => $data->preAuthorization ? 'Auth' : 'Sale',
            'OrderId' => $this->formatOrderId($data->orderId),
            'Amount' => $this->formatAmount($data->money()),
            'CurrencyCode' => $this->currencyCode($data->currency),
            'MerchantReturnURL' => $this->successUrl($data),
            'InstallmentCount' => $this->formatInstallment($data->installments()),
            'Language' => strtolower($data->lang) === 'en' ? 'en' : 'tr',
            'TxnState' => 'INITIAL',
            'OpenNewWindow' => '0',
        ];

        if ($data->paymentModel === CreatePaymentData::MODEL_3D_HOST) {
            // UseOOS=1: kart bilgileri bankanın ortak ödeme sayfasında toplanır.
            $inputs['UseOOS'] = '1';
            $inputs['MacParams'] = 'MerchantNo:TerminalNo:Amount';
        } else {
            $card = $this->requireCard($data);

            $inputs['CardNo'] = $card->number;
            // Bankanın dokümanı MacParams içinde 'ExpireDate' derken,
            // istekte alanın adı 'ExpiredDate'tir.
            $inputs['ExpiredDate'] = $card->expiry('ym');
            $inputs['Cvv'] = $card->cvv;
            $inputs['CardHolderName'] = $card->holderName ?? '';
            $inputs['UseOOS'] = '0';
            $inputs['MacParams'] = 'MerchantNo:TerminalNo:CardNo:Cvc2:ExpireDate:Amount';
        }

        $inputs['Mac'] = $this->create3dMac($inputs);

        return $inputs;
    }

    /**
     * 3D form MAC'i.
     *
     * MerchantNo + TerminalNo + CardNo + Cvv + ExpiredDate + Amount + secretKey
     * (3D Host akışında kart alanları yoktur).
     *
     * @param  array<string, mixed>  $inputs
     */
    protected function create3dMac(array $inputs): string
    {
        return $this->hash(implode('', [
            (string) $inputs['MerchantNo'],
            (string) $inputs['TerminalNo'],
            (string) ($inputs['CardNo'] ?? ''),
            (string) ($inputs['Cvv'] ?? ''),
            (string) ($inputs['ExpiredDate'] ?? ''),
            (string) $inputs['Amount'],
            $this->config->secretKey,
        ]));
    }

    /**
     * Banka dönüşündeki MAC, `MacParams` içinde bildirilen alanlardan üretilir.
     *
     * @param  array<string, mixed>  $payload
     */
    protected function checkCallbackHash(array $payload): bool
    {
        $incoming = $this->pick($payload, ['Mac']);
        $macParams = $this->pick($payload, ['MacParams']);

        if ($incoming === null || $macParams === null) {
            return false;
        }

        $values = '';

        foreach (explode(':', $macParams) as $field) {
            if ($field === '') {
                continue;
            }

            $values .= $this->pick($payload, [$field], '') ?? '';
        }

        return hash_equals($this->hash($values.$this->config->secretKey), $incoming);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function is3dAuthSuccess(array $payload): bool
    {
        return in_array($this->pick($payload, ['MdStatus']), ['1', '2', '3', '4'], true);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    protected function provision(array $payload): array
    {
        $installment = (int) ($this->pick($payload, ['InstallmentCount'], '0') ?? '0');

        $request = [
            'ApiType' => 'JSON',
            'ApiVersion' => self::API_VERSION,
            'MerchantNo' => $this->config->merchantId,
            'TerminalNo' => $this->config->terminalId,
            'PaymentInstrumentType' => 'CARD',
            'IsEncrypted' => 'N',
            'IsTDSecureMerchant' => 'Y',
            'IsMailOrder' => 'N',
            'ThreeDSecureData' => [
                'SecureTransactionId' => $this->pick($payload, ['SecureTransactionId'], '') ?? '',
                'CavvData' => $this->pick($payload, ['CAVV'], '') ?? '',
                'Eci' => $this->pick($payload, ['ECI'], '') ?? '',
                'MdStatus' => (int) ($this->pick($payload, ['MdStatus'], '0') ?? '0'),
                'MD' => $this->pick($payload, ['MD'], '') ?? '',
            ],
            'MACParams' => 'MerchantNo:TerminalNo:SecureTransactionId:CavvData:Eci:MdStatus',
            'Amount' => (int) ($this->pick($payload, ['Amount'], '0') ?? '0'),
            'CurrencyCode' => $this->pick($payload, ['CurrencyCode'], 'TL') ?? 'TL',
            'PointAmount' => 0,
            'OrderId' => $this->pick($payload, ['OrderId'], '') ?? '',
            'InstallmentCount' => $this->formatInstallment($installment),
            'InstallmentType' => $installment > 1 ? 'Y' : 'N',
        ];

        $request['MAC'] = $this->hash(implode('', [
            (string) $request['MerchantNo'],
            (string) $request['TerminalNo'],
            (string) $request['ThreeDSecureData']['SecureTransactionId'],
            (string) $request['ThreeDSecureData']['CavvData'],
            (string) $request['ThreeDSecureData']['Eci'],
            (string) $request['ThreeDSecureData']['MdStatus'],
            $this->config->secretKey,
        ]));

        return $this->postJson($this->pick($payload, ['TransactionType']) === 'Auth' ? 'Auth' : 'Sale', $request);
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
        $approved = $this->responseCode($provision) === self::SUCCESS_CODE;

        return new VerificationResponse(
            success: $approved,
            paymentId: $this->pick($provision, ['ReferenceCode', 'AuthCode']) ?? $this->extractOrderId($payload),
            status: $approved ? 'success' : 'failed',
            raw: ['callback' => $payload, 'provision' => $provision],
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function extractOrderId(array $payload): ?string
    {
        return $this->pick($payload, ['OrderId']);
    }

    /**
     * 3D'siz doğrudan provizyon.
     */
    protected function nonSecurePayment(CreatePaymentData $data): PaymentResponse
    {
        $card = $this->requireCard($data);
        $installment = $data->installments();

        $request = [
            'ApiType' => 'JSON',
            'ApiVersion' => self::API_VERSION,
            'MACParams' => 'MerchantNo:TerminalNo:CardNo:Cvc2:ExpireDate:Amount',
            'MerchantNo' => $this->config->merchantId,
            'TerminalNo' => $this->config->terminalId,
            'CardInformationData' => [
                'CardNo' => $card->number,
                'ExpireDate' => $card->expiry('ym'),
                'Cvc2' => $card->cvv,
                'CardHolderName' => $card->holderName ?? '',
            ],
            'IsMailOrder' => 'N',
            'PaymentInstrumentType' => 'CARD',
            'Amount' => $data->money()->minorUnits,
            'CurrencyCode' => $this->currencyCode($data->currency),
            'OrderId' => $this->formatOrderId($data->orderId),
            'InstallmentCount' => $this->formatInstallment($installment),
            'InstallmentType' => $installment > 1 ? 'Y' : 'N',
        ];

        $request['MAC'] = $this->macFromParams($request, $request['MACParams']);

        $response = $this->postJson('Sale', $request);
        $approved = $this->responseCode($response) === self::SUCCESS_CODE;

        return new PaymentResponse(
            success: $approved,
            paymentId: $this->pick($response, ['ReferenceCode', 'AuthCode']) ?? $data->orderId,
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
        return $this->mapReversal($this->postJson('Return', $this->reversalRequest($data, 'Sale')));
    }

    /**
     * Gün sonu öncesi işlem iptali.
     */
    public function cancel(RefundPaymentData $data): RefundResponse
    {
        return $this->mapReversal($this->postJson('Reverse', $this->reversalRequest($data, 'Sale')));
    }

    /**
     * @return array<string, mixed>
     */
    protected function reversalRequest(RefundPaymentData $data, string $originalTxType): array
    {
        $paymentModel = (string) ($data->meta('payment_model') ?? CreatePaymentData::MODEL_3D_SECURE);
        $referenceCode = $data->meta('ref_ret_num') ?? $data->meta('reference_code');

        $request = [
            'ApiType' => 'JSON',
            'ApiVersion' => self::API_VERSION,
            'MerchantNo' => $this->config->merchantId,
            'TerminalNo' => $this->config->terminalId,
            'MACParams' => 'MerchantNo:TerminalNo:ReferenceCode:OrderId',
            'ReferenceCode' => null,
            'OrderId' => null,
            'TransactionType' => $originalTxType,
        ];

        if (is_string($referenceCode) && $referenceCode !== '') {
            $request['ReferenceCode'] = $referenceCode;
        } else {
            $request['OrderId'] = $this->formatReversalOrderId($data->paymentId, $paymentModel);
        }

        if ($paymentModel === CreatePaymentData::MODEL_NON_SECURE && ($amount = $data->money()) !== null) {
            $request['Amount'] = $amount->minorUnits;
            $request['CurrencyCode'] = $this->currencyCode($data->currency);
        }

        $request['MAC'] = $this->macFromParams($request, $request['MACParams']);

        return $request;
    }

    /**
     * @param  array<string, mixed>  $response
     */
    protected function mapReversal(array $response): RefundResponse
    {
        $approved = $this->responseCode($response) === self::SUCCESS_CODE;

        return new RefundResponse(
            success: $approved,
            refundId: $this->pick($response, ['ReferenceCode', 'AuthCode']),
            errorMessage: $approved ? null : $this->responseDescription($response),
            raw: $response,
        );
    }

    /**
     * `MACParams` içinde bildirilen alanlardan MAC üretir.
     *
     * @param  array<string, mixed>  $request
     */
    protected function macFromParams(array $request, string $macParams): string
    {
        $values = '';

        foreach (explode(':', $macParams) as $field) {
            if ($field === '') {
                continue;
            }

            $values .= $this->findNested($request, $field);
        }

        return $this->hash($values.$this->config->secretKey);
    }

    /**
     * İç içe istek dizisinde bir alanı arar.
     *
     * @param  array<string, mixed>  $data
     */
    protected function findNested(array $data, string $key): string
    {
        foreach ($data as $name => $value) {
            if ($name === $key && is_scalar($value)) {
                return (string) $value;
            }

            if (is_array($value)) {
                $found = $this->findNested($value, $key);

                if ($found !== '') {
                    return $found;
                }
            }
        }

        return '';
    }

    protected function hash(string $value): string
    {
        return base64_encode(hash('sha256', $value, true));
    }

    /**
     * PosNet V1 tutarları kuruş cinsinden tam sayı olarak bekler.
     */
    protected function formatAmount(Money $money): string
    {
        return $money->toMinorUnitsString();
    }

    protected function formatInstallment(int $installment): string
    {
        return $installment > 1 ? (string) $installment : '0';
    }

    /**
     * @throws PaymentFailedException Para birimi desteklenmiyorsa
     */
    protected function currencyCode(string $currency): string
    {
        $code = self::CURRENCIES[strtoupper($currency)] ?? null;

        if ($code === null) {
            throw new PaymentFailedException(
                message: sprintf("PosNet V1 '%s' para birimini desteklemiyor.", $currency),
                context: ['supported' => array_keys(self::CURRENCIES)],
            );
        }

        return $code;
    }

    /**
     * @throws PaymentFailedException Sipariş numarası çok uzunsa
     */
    protected function formatOrderId(string $orderId, int $length = self::ORDER_ID_LENGTH, string $prefix = ''): string
    {
        $padLength = $length - strlen($prefix);

        if (strlen($orderId) > $padLength) {
            throw new PaymentFailedException(
                message: sprintf(
                    "PosNet V1 sipariş numarası en fazla %d karakter olabilir; '%s' %d karakter.",
                    $padLength,
                    $orderId,
                    strlen($orderId),
                ),
                context: ['bank' => $this->config->bank],
            );
        }

        return $prefix.str_pad($orderId, $padLength, '0', STR_PAD_LEFT);
    }

    protected function formatReversalOrderId(string $orderId, string $paymentModel): string
    {
        $prefix = $paymentModel === CreatePaymentData::MODEL_3D_SECURE ? self::ORDER_ID_3D_PREFIX : '';

        return $this->formatOrderId($orderId, self::ORDER_ID_TOTAL_LENGTH, $prefix);
    }

    protected function posNetId(): string
    {
        $id = $this->config->extra('posnet_id');

        if (! is_string($id) || $id === '') {
            throw new PaymentFailedException(
                message: "PosNet V1 için extra['posnet_id'] zorunludur.",
                context: ['bank' => $this->config->bank],
            );
        }

        return $id;
    }

    /**
     * @param  array<string, mixed>  $response
     */
    protected function responseCode(array $response): ?string
    {
        $code = $response['ServiceResponseData']['ResponseCode'] ?? null;

        return is_scalar($code) ? (string) $code : null;
    }

    /**
     * @param  array<string, mixed>  $response
     */
    protected function responseDescription(array $response): ?string
    {
        $message = $response['ServiceResponseData']['ResponseDescription'] ?? null;

        return is_scalar($message) ? (string) $message : null;
    }

    /**
     * PosNet V1 işlem tipini API yolunun son parçası olarak bekler.
     *
     * @param  array<string, mixed>  $request
     * @return array<string, mixed>
     */
    protected function postJson(string $operation, array $request): array
    {
        return $this->client->postJson(
            url: rtrim($this->config->endpoint('payment_api'), '/').'/'.$operation,
            data: $request,
        );
    }

    /**
     * Sipariş durumunu sorgular (`TransactionInquiry`).
     */
    public function status(string $orderId, array $context = []): StatusResponse
    {
        $paymentModel = (string) ($context['payment_model'] ?? CreatePaymentData::MODEL_3D_SECURE);

        $request = [
            'ApiType' => 'JSON',
            'ApiVersion' => self::API_VERSION,
            'MerchantNo' => $this->config->merchantId,
            'TerminalNo' => $this->config->terminalId,
            'MACParams' => 'MerchantNo:TerminalNo',
            'IsEncrypted' => 'N',
            'OrderId' => $this->formatReversalOrderId($orderId, $paymentModel),
        ];

        $request['MAC'] = $this->macFromParams($request, $request['MACParams']);

        $response = $this->postJson('TransactionInquiry', $request);

        if ($this->responseCode($response) !== self::SUCCESS_CODE) {
            return StatusResponse::notFound($orderId, $response);
        }

        $transactions = $response['TransactionData'] ?? [];
        $transaction = is_array($transactions) && array_is_list($transactions)
            ? (array) (end($transactions) ?: [])
            : (is_array($transactions) ? $transactions : []);

        return new StatusResponse(
            found: true,
            status: match (strtolower((string) ($this->pick($transaction, ['TransactionType']) ?? ''))) {
                'reverse', 'void' => StatusResponse::STATUS_CANCELLED,
                'return' => StatusResponse::STATUS_REFUNDED,
                'auth' => StatusResponse::STATUS_PRE_AUTHORIZED,
                default => StatusResponse::STATUS_PAID,
            },
            orderId: $orderId,
            paymentId: $this->pick($transaction, ['ReferenceCode', 'AuthCode']),
            amount: ($amount = $this->pick($transaction, ['Amount'])) !== null && is_numeric($amount)
                ? Money::fromMinorUnits((int) $amount)
                : null,
            transactionTime: $this->pick($transaction, ['TransactionDate']),
            raw: $response,
        );
    }

    /**
     * Ön provizyonu kapatır (`Capture`).
     */
    public function capture(CapturePaymentData $data): PaymentResponse
    {
        $referenceCode = $data->meta('ref_ret_num') ?? $data->meta('reference_code');

        $request = [
            'ApiType' => 'JSON',
            'ApiVersion' => self::API_VERSION,
            'MerchantNo' => $this->config->merchantId,
            'TerminalNo' => $this->config->terminalId,
            'MACParams' => 'MerchantNo:TerminalNo:ReferenceCode:OrderId',
            'ReferenceCode' => is_string($referenceCode) && $referenceCode !== '' ? $referenceCode : null,
            'OrderId' => is_string($referenceCode) && $referenceCode !== ''
                ? null
                : $this->formatOrderId($data->orderId),
            'Amount' => ($data->money() ?? Money::fromMinorUnits(0, $data->currency))->minorUnits,
            'CurrencyCode' => $this->currencyCode($data->currency),
        ];

        $request['MAC'] = $this->macFromParams($request, $request['MACParams']);

        $response = $this->postJson('Capture', $request);
        $approved = $this->responseCode($response) === self::SUCCESS_CODE;

        return new PaymentResponse(
            success: $approved,
            paymentId: $this->pick($response, ['ReferenceCode', 'AuthCode']) ?? $data->orderId,
            errorMessage: $approved ? null : $this->responseDescription($response),
            raw: $response,
            errorCode: $approved ? null : $this->responseCode($response),
        );
    }
}
