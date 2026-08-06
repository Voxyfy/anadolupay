<?php

declare(strict_types=1);

namespace Voxyfy\AnadoluPay\Gateways\Bank;

use Voxyfy\AnadoluPay\DTO\CreatePaymentData;
use Voxyfy\AnadoluPay\DTO\PaymentResponse;
use Voxyfy\AnadoluPay\DTO\RefundPaymentData;
use Voxyfy\AnadoluPay\DTO\RefundResponse;
use Voxyfy\AnadoluPay\DTO\VerificationResponse;
use Voxyfy\AnadoluPay\Support\Bank\Currency;

/**
 * PayFor (Finansbank / Enpara / Ziraat Katılım) Sanal POS Driver'ı
 *
 * Protokol özeti:
 *   - 3D formu düz alanlardan oluşur; imza sha1 + base64'tür ve alanlar
 *     ayraçsız birleştirilir: MbrId + OrderId + PurchAmount + OkUrl +
 *     FailUrl + TxnType + InstallmentCount + Rnd + secretKey.
 *   - Dönüş imzası farklı bir alan sırası kullanır: MerchantId + secretKey +
 *     OrderId + AuthCode + ProcReturnCode + 3DStatus + ResponseRnd + UserCode.
 *   - Provizyon `PayforRequest` kök elemanlı XML ile yapılır ve 3D dönüşünde
 *     gelen `RequestGuid` üzerinden tamamlanır.
 */
class PayForGateway extends AbstractBankGateway
{
    /** Başarılı işlem kodu. */
    protected const SUCCESS_CODE = '00';

    /** MOTO: 0 => e-ticaret işlemi. */
    protected const MOTO = '0';

    /** Üye işyeri grup numarası; PayFor kurulumlarında sabittir. */
    protected const DEFAULT_MBR_ID = '5';

    /**
     * @return array<string, scalar>
     */
    protected function build3dFormFields(CreatePaymentData $data): array
    {
        $this->config->require(['merchantId', 'username', 'secretKey']);

        $inputs = [
            'MbrId' => $this->mbrId(),
            'MerchantID' => $this->config->merchantId,
            'UserCode' => $this->config->username,
            'OrderId' => $data->orderId,
            'Lang' => $this->lang($data->lang),
            'SecureType' => $this->secureType($data->paymentModel),
            'TxnType' => 'Auth',
            'PurchAmount' => $this->formatAmount($data->amount),
            'InstallmentCount' => $this->formatInstallment($data->installments()),
            'Currency' => Currency::numeric($data->currency),
            'OkUrl' => $this->successUrl($data),
            'FailUrl' => $this->failUrl($data),
            'Rnd' => $this->randomString(),
        ];

        if ($data->paymentModel !== CreatePaymentData::MODEL_3D_HOST) {
            $card = $this->requireCard($data);

            $inputs['CardHolderName'] = $card->holderName ?? '';
            $inputs['Pan'] = $card->number;
            $inputs['Expiry'] = $card->expiry('my');
            $inputs['Cvv2'] = $card->cvv;
        }

        $inputs['Hash'] = $this->create3dHash($inputs);

        return $inputs;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function checkCallbackHash(array $payload): bool
    {
        $incoming = $this->pick($payload, ['ResponseHash']);

        if ($incoming === null) {
            return false;
        }

        $expected = $this->hash(implode('', [
            $this->config->merchantId,
            $this->config->secretKey,
            $this->pick($payload, ['OrderId'], '') ?? '',
            $this->pick($payload, ['AuthCode'], '') ?? '',
            $this->pick($payload, ['ProcReturnCode'], '') ?? '',
            $this->pick($payload, ['3DStatus'], '') ?? '',
            $this->pick($payload, ['ResponseRnd'], '') ?? '',
            $this->config->username,
        ]));

        return hash_equals($expected, $incoming);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function is3dAuthSuccess(array $payload): bool
    {
        return in_array($this->pick($payload, ['3DStatus']), ['1', '2', '3', '4'], true);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function paymentModelOf(array $payload): string
    {
        return match ($this->pick($payload, ['SecureType'])) {
            '3DPay' => CreatePaymentData::MODEL_3D_PAY,
            '3DHost' => CreatePaymentData::MODEL_3D_HOST,
            default => CreatePaymentData::MODEL_3D_SECURE,
        };
    }

    /**
     * PayFor provizyonu, 3D dönüşünde gelen `RequestGuid` ile tamamlanır.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    protected function provision(array $payload): array
    {
        return $this->postXml([
            'RequestGuid' => $this->pick($payload, ['RequestGuid'], '') ?? '',
            'UserCode' => $this->config->username,
            'UserPass' => $this->config->password,
            'OrderId' => $this->extractOrderId($payload) ?? '',
            'SecureType' => '3DModelPayment',
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function mapCallbackResponse(array $payload): VerificationResponse
    {
        $approved = $this->pick($payload, ['ProcReturnCode']) === self::SUCCESS_CODE;

        return new VerificationResponse(
            success: $approved,
            paymentId: $this->pick($payload, ['TransId', 'AuthCode']) ?? $this->extractOrderId($payload),
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
        return $this->pick($payload, ['OrderId', 'TransId']);
    }

    /**
     * 3D'siz doğrudan provizyon.
     */
    protected function nonSecurePayment(CreatePaymentData $data): PaymentResponse
    {
        $card = $this->requireCard($data);

        $response = $this->postXml($this->accountData() + [
            'MOTO' => self::MOTO,
            'OrderId' => $data->orderId,
            'SecureType' => 'NonSecure',
            'TxnType' => 'Auth',
            'PurchAmount' => $this->formatAmount($data->amount),
            'Currency' => Currency::numeric($data->currency),
            'InstallmentCount' => $this->formatInstallment($data->installments()),
            'Lang' => $this->lang($data->lang),
            'CardHolderName' => $card->holderName ?? '',
            'Pan' => $card->number,
            'Expiry' => $card->expiry('my'),
            'Cvv2' => $card->cvv,
        ]);

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
     * Tam veya kısmi iade.
     */
    public function refund(RefundPaymentData $data): RefundResponse
    {
        $request = $this->accountData() + [
            'SecureType' => 'NonSecure',
            'Lang' => $this->lang($this->config->lang),
            'OrgOrderId' => $data->paymentId,
            'TxnType' => 'Refund',
            'Currency' => Currency::numeric($data->currency),
        ];

        if ($data->amount !== null) {
            $request['PurchAmount'] = $this->formatAmount($data->amount);
        }

        return $this->mapReversal($this->postXml($request));
    }

    /**
     * Gün sonu öncesi işlem iptali.
     */
    public function cancel(RefundPaymentData $data): RefundResponse
    {
        return $this->mapReversal($this->postXml($this->accountData() + [
            'OrgOrderId' => $data->paymentId,
            'SecureType' => 'NonSecure',
            'TxnType' => 'Void',
            'Currency' => Currency::numeric($data->currency),
            'Lang' => $this->lang($this->config->lang),
        ]));
    }

    /**
     * Sipariş durumunu sorgular.
     *
     * @return array<string, mixed>
     */
    public function status(string $orderId): array
    {
        return $this->postXml($this->accountData() + [
            'OrgOrderId' => $orderId,
            'SecureType' => 'Inquiry',
            'Lang' => $this->lang($this->config->lang),
            'TxnType' => 'OrderInquiry',
        ]);
    }

    /**
     * @param  array<string, mixed>  $response
     */
    protected function mapReversal(array $response): RefundResponse
    {
        $approved = $this->pick($response, ['ProcReturnCode']) === self::SUCCESS_CODE;

        return new RefundResponse(
            success: $approved,
            refundId: $this->pick($response, ['TransId']),
            errorMessage: $approved ? null : $this->pick($response, ['ErrMsg']),
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
        return $this->hash(implode('', [
            (string) $inputs['MbrId'],
            (string) $inputs['OrderId'],
            (string) $inputs['PurchAmount'],
            (string) $inputs['OkUrl'],
            (string) $inputs['FailUrl'],
            (string) $inputs['TxnType'],
            (string) $inputs['InstallmentCount'],
            (string) $inputs['Rnd'],
            $this->config->secretKey,
        ]));
    }

    protected function hash(string $value): string
    {
        return base64_encode(hash('sha1', $value, true));
    }

    /**
     * PayFor tutarı PHP'nin doğal float gösterimiyle bekler.
     */
    protected function formatAmount(float $amount): string
    {
        return (string) $amount;
    }

    /**
     * Tek çekimde taksit alanı '0' gönderilir.
     */
    protected function formatInstallment(int $installment): string
    {
        return $installment > 1 ? (string) $installment : '0';
    }

    protected function lang(string $lang): string
    {
        return strtoupper($lang) === 'EN' ? 'EN' : 'TR';
    }

    protected function secureType(string $paymentModel): string
    {
        return match ($paymentModel) {
            CreatePaymentData::MODEL_3D_PAY => '3DPay',
            CreatePaymentData::MODEL_3D_HOST => '3DHost',
            CreatePaymentData::MODEL_NON_SECURE => 'NonSecure',
            default => '3DModel',
        };
    }

    protected function mbrId(): string
    {
        $mbrId = $this->config->extra('mbr_id');

        return is_string($mbrId) && $mbrId !== '' ? $mbrId : self::DEFAULT_MBR_ID;
    }

    /**
     * @return array<string, string>
     */
    protected function accountData(): array
    {
        $this->config->require(['merchantId', 'username', 'password']);

        return [
            'MerchantId' => $this->config->merchantId,
            'UserCode' => $this->config->username,
            'UserPass' => $this->config->password,
            'MbrId' => $this->mbrId(),
        ];
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
            root: 'PayforRequest',
        );
    }
}
