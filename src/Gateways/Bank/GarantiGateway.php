<?php

declare(strict_types=1);

namespace Voxyfy\AnadoluPay\Gateways\Bank;

use Voxyfy\AnadoluPay\DTO\CreatePaymentData;
use Voxyfy\AnadoluPay\DTO\PaymentResponse;
use Voxyfy\AnadoluPay\DTO\RefundPaymentData;
use Voxyfy\AnadoluPay\DTO\RefundResponse;
use Voxyfy\AnadoluPay\DTO\VerificationResponse;
use Voxyfy\AnadoluPay\Exceptions\PaymentFailedException;
use Voxyfy\AnadoluPay\Support\Bank\Currency;

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
class GarantiGateway extends AbstractBankGateway
{
    /** Bankanın başarılı işlem için döndürdüğü yanıt kodu. */
    protected const SUCCESS_CODE = '00';

    /** Garanti API sürümü. */
    protected const API_VERSION = '512';

    /** MotoInd: N => e-ticaret işlemi, Y => mail order. */
    protected const MOTO = 'N';

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
            'txntype' => 'sales',
            'txnamount' => $this->formatAmount($data->amount),
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
                'Type' => 'sales',
                'InstallmentCnt' => $this->formatInstallment($data->installments()),
                'Amount' => $this->formatAmount($data->amount),
                'CurrencyCode' => Currency::numeric($data->currency),
                'CardholderPresentCode' => '0',
                'MotoInd' => self::MOTO,
            ],
        ];

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
    public function refund(RefundPaymentData $data): RefundResponse
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
                'Amount' => $this->formatAmount($data->amount ?? 0.01),
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
    protected function formatAmount(float $amount): string
    {
        return $this->amountInMinorUnits($amount);
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
            data: $request,
            root: 'GVPSRequest',
        );
    }
}
