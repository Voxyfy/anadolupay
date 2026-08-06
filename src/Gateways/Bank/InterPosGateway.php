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
 * Denizbank InterPos (Intertech VPOS) Driver'ı
 *
 * Protokol özeti:
 *   - Hem 3D formu hem de sunucu istekleri düz form alanlarından oluşur;
 *     XML kullanılmaz.
 *   - 3D form imzası: ShopCode + OrderId + PurchAmount + OkUrl + FailUrl +
 *     TxnType + InstallmentCount + Rnd + secretKey, ayraçsız, sha1 + base64.
 *   - Dönüş imzası, bankanın `HASHPARAMS` alanında iki nokta ile bildirdiği
 *     alanların değerleri + secretKey üzerinden hesaplanır.
 */
class InterPosGateway extends AbstractBankGateway
{
    /** Başarılı işlem kodu. */
    protected const SUCCESS_CODE = '00';

    /** MOTO: 0 => e-ticaret işlemi. */
    protected const MOTO = '0';

    /** Kart tipi kodları. */
    protected const CARD_TYPES = [
        'visa' => '0',
        'mastercard' => '1',
        'amex' => '2',
        'troy' => '3',
    ];

    /**
     * @return array<string, scalar>
     */
    protected function build3dFormFields(CreatePaymentData $data): array
    {
        $this->config->require(['merchantId', 'secretKey']);

        $inputs = [
            'ShopCode' => $this->config->merchantId,
            'TxnType' => 'Auth',
            'SecureType' => $this->secureType($data->paymentModel),
            'PurchAmount' => $this->formatAmount($data->amount),
            'OrderId' => $data->orderId,
            'OkUrl' => $this->successUrl($data),
            'FailUrl' => $this->failUrl($data),
            'Rnd' => $this->randomString(),
            'Lang' => $this->lang($data->lang),
            'Currency' => Currency::numeric($data->currency),
            'InstallmentCount' => $this->formatInstallment($data->installments()),
        ];

        if ($data->paymentModel !== CreatePaymentData::MODEL_3D_HOST) {
            $card = $this->requireCard($data);

            $inputs['CardType'] = $this->cardType($card->type);
            $inputs['Pan'] = $card->number;
            $inputs['Expiry'] = $card->expiry('my');
            $inputs['Cvv2'] = $card->cvv;
        }

        $inputs['Hash'] = $this->create3dHash($inputs);

        return $inputs;
    }

    /**
     * Banka dönüşte hash'e giren alan adlarını `HASHPARAMS` içinde
     * iki nokta ile ayırarak bildirir.
     *
     * @param  array<string, mixed>  $payload
     */
    protected function checkCallbackHash(array $payload): bool
    {
        $incoming = $this->pick($payload, ['HASH']);
        $hashParams = $this->pick($payload, ['HASHPARAMS']);

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

        return hash_equals($this->hash($values.$this->config->secretKey), $incoming);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function is3dAuthSuccess(array $payload): bool
    {
        return in_array($this->pick($payload, ['3DStatus', 'mdStatus']), ['1', '2', '3', '4'], true);
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
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    protected function provision(array $payload): array
    {
        return $this->postForm($this->accountData() + [
            'TxnType' => 'Auth',
            'SecureType' => 'NonSecure',
            'OrderId' => $this->extractOrderId($payload) ?? '',
            'PurchAmount' => $this->pick($payload, ['PurchAmount'], '') ?? '',
            'Currency' => $this->pick($payload, ['Currency'], '') ?? '',
            'InstallmentCount' => $this->pick($payload, ['InstallmentCount'], '') ?? '',
            'MD' => $this->pick($payload, ['MD'], '') ?? '',
            'PayerTxnId' => $this->pick($payload, ['PayerTxnId'], '') ?? '',
            'Eci' => $this->pick($payload, ['Eci'], '') ?? '',
            'PayerAuthenticationCode' => $this->pick($payload, ['PayerAuthenticationCode'], '') ?? '',
            'MOTO' => self::MOTO,
            'Lang' => $this->lang($this->config->lang),
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
            paymentId: $this->pick($payload, ['TransId', 'HostRefNum']) ?? $this->extractOrderId($payload),
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

        $response = $this->postForm($this->accountData() + [
            'TxnType' => 'Auth',
            'SecureType' => 'NonSecure',
            'OrderId' => $data->orderId,
            'PurchAmount' => $this->formatAmount($data->amount),
            'Currency' => Currency::numeric($data->currency),
            'InstallmentCount' => $this->formatInstallment($data->installments()),
            'MOTO' => self::MOTO,
            'Lang' => $this->lang($data->lang),
            'CardType' => $this->cardType($card->type),
            'Pan' => $card->number,
            'Expiry' => $card->expiry('my'),
            'Cvv2' => $card->cvv,
        ]);

        $approved = $this->pick($response, ['ProcReturnCode']) === self::SUCCESS_CODE;

        return new PaymentResponse(
            success: $approved,
            paymentId: $this->pick($response, ['TransId']) ?? $data->orderId,
            errorMessage: $approved ? null : $this->pick($response, ['ErrorMessage', 'ErrorCode']),
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
            'orgOrderId' => $data->paymentId,
            'TxnType' => 'Refund',
            'SecureType' => 'NonSecure',
            'Lang' => $this->lang($this->config->lang),
            'MOTO' => self::MOTO,
        ];

        if ($data->amount !== null) {
            $request['PurchAmount'] = $this->formatAmount($data->amount);
        }

        return $this->mapReversal($this->postForm($request));
    }

    /**
     * Gün sonu öncesi işlem iptali.
     */
    public function cancel(RefundPaymentData $data): RefundResponse
    {
        return $this->mapReversal($this->postForm($this->accountData() + [
            'orgOrderId' => $data->paymentId,
            'TxnType' => 'Void',
            'SecureType' => 'NonSecure',
            'Lang' => $this->lang($this->config->lang),
        ]));
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
            errorMessage: $approved ? null : $this->pick($response, ['ErrorMessage', 'ErrorCode']),
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
            (string) $inputs['ShopCode'],
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
     * InterPos tutarı PHP'nin doğal float gösterimiyle bekler.
     */
    protected function formatAmount(float $amount): string
    {
        return (string) $amount;
    }

    /**
     * Tek çekimde taksit alanı boş gönderilir.
     */
    protected function formatInstallment(int $installment): string
    {
        return $installment > 1 ? (string) $installment : '';
    }

    protected function lang(string $lang): string
    {
        return strtolower($lang) === 'en' ? 'en' : 'tr';
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

    protected function cardType(?string $type): string
    {
        return self::CARD_TYPES[strtolower((string) $type)] ?? '';
    }

    /**
     * @return array<string, string>
     */
    protected function accountData(): array
    {
        $this->config->require(['merchantId', 'username', 'password']);

        return [
            'UserCode' => $this->config->username,
            'UserPass' => $this->config->password,
            'ShopCode' => $this->config->merchantId,
        ];
    }

    /**
     * @param  array<string, scalar>  $request
     * @return array<string, mixed>
     */
    protected function postForm(array $request): array
    {
        return $this->client->postForm(
            url: $this->config->endpoint('payment_api'),
            fields: $request,
        );
    }
}
