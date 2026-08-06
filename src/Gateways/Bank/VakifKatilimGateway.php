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
 * Vakıf Katılım Sanal POS (BOA) Driver'ı
 *
 * Kuveyt Türk ile aynı imza algoritmasını kullanır ancak istek şeması ve
 * uç noktaları farklıdır: işlem tipi URL'in son parçasıdır
 * (`.../ThreeDModelPayGate`, `.../ThreeDModelProvisionGate`, `.../DrawBack`).
 *
 * İstekler `VPosMessageContract` kök elemanlı XML'dir (ISO-8859-1).
 */
class VakifKatilimGateway extends AbstractBankGateway
{
    /** Başarılı işlem kodu. */
    protected const SUCCESS_CODE = '00';

    /** BOA API sürümü. */
    protected const API_VERSION = '1.0.0';

    /** İsteklerin XML kök elemanı. */
    protected const XML_ROOT = 'VPosMessageContract';

    /**
     * 3D formunu bankadan alır; Vakıf Katılım da hazır HTML döner.
     */
    public function createPayment(CreatePaymentData $data): PaymentResponse
    {
        if ($data->paymentModel === CreatePaymentData::MODEL_NON_SECURE) {
            return $this->nonSecurePayment($data);
        }

        $html = $this->client->postXmlForRawBody(
            url: $this->operationUrl('ThreeDModelPayGate'),
            data: $this->build3dFormFields($data),
            root: self::XML_ROOT,
            encoding: 'ISO-8859-1',
        );

        return new PaymentResponse(
            success: $html !== '',
            paymentId: $data->orderId,
            raw: ['html_length' => strlen($html)],
            htmlContent: $html,
        );
    }

    /**
     * @return array<string, mixed>
     */
    protected function build3dFormFields(CreatePaymentData $data): array
    {
        $this->config->require(['merchantId', 'username', 'secretKey']);

        $request = $this->accountData() + [
            'APIVersion' => self::API_VERSION,
            'HashPassword' => $this->hash($this->config->secretKey),
            'TransactionSecurity' => '3',
            'InstallmentCount' => $this->formatInstallment($data->installments()),
            'Amount' => $this->formatAmount($data->amount),
            'DisplayAmount' => $this->formatAmount($data->amount),
            'FECCurrencyCode' => Currency::numeric($data->currency),
            'MerchantOrderId' => $data->orderId,
            'OkUrl' => $this->successUrl($data),
            'FailUrl' => $this->failUrl($data),
        ];

        if ($data->paymentModel !== CreatePaymentData::MODEL_3D_HOST) {
            $card = $this->requireCard($data);

            $request['CardHolderName'] = $card->holderName ?? '';
            $request['CardNumber'] = $card->number;
            $request['CardExpireDateYear'] = $card->expireYearShort();
            $request['CardExpireDateMonth'] = $card->expireMonth;
            $request['CardCVV2'] = $card->cvv;
        }

        $request['HashData'] = $this->createHash($request);

        return $request;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function checkCallbackHash(array $payload): bool
    {
        // Vakıf Katılım dönüşte doğrulanacak bir hash göndermez.
        return true;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function is3dAuthSuccess(array $payload): bool
    {
        return $this->pick($payload, ['ResponseCode']) === self::SUCCESS_CODE;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    protected function provision(array $payload): array
    {
        $request = $this->accountData() + [
            'OkUrl' => $this->pick($payload, ['OkUrl'], '') ?? '',
            'FailUrl' => $this->pick($payload, ['FailUrl'], '') ?? '',
            'HashData' => '',
            'APIVersion' => self::API_VERSION,
            'AdditionalData' => [
                'AdditionalDataList' => [
                    'VPosAdditionalData' => [
                        'Key' => 'MD',
                        'Data' => $this->pick($payload, ['MD'], '') ?? '',
                    ],
                ],
            ],
            'InstallmentCount' => $this->pick($payload, ['InstallmentCount'], '0') ?? '0',
            'Amount' => $this->pick($payload, ['Amount'], '') ?? '',
            'MerchantOrderId' => $this->extractOrderId($payload) ?? '',
            'TransactionSecurity' => '3',
        ];

        $request['HashData'] = $this->createHash($request);

        return $this->postXml('ThreeDModelProvisionGate', $request);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function mapCallbackResponse(array $payload): VerificationResponse
    {
        $approved = $this->pick($payload, ['ResponseCode']) === self::SUCCESS_CODE;

        return new VerificationResponse(
            success: $approved,
            paymentId: $this->extractOrderId($payload),
            status: $approved ? 'success' : 'failed',
            raw: $payload,
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $provision
     */
    protected function mapProvisionResponse(array $payload, array $provision): VerificationResponse
    {
        $approved = $this->pick($provision, ['ResponseCode']) === self::SUCCESS_CODE;

        return new VerificationResponse(
            success: $approved,
            paymentId: $this->pick($provision, ['OrderId']) ?? $this->extractOrderId($payload),
            status: $approved ? 'success' : 'failed',
            raw: ['callback' => $payload, 'provision' => $provision],
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function extractOrderId(array $payload): ?string
    {
        return $this->pick($payload, ['MerchantOrderId', 'OrderId']);
    }

    /**
     * 3D'siz doğrudan provizyon.
     */
    protected function nonSecurePayment(CreatePaymentData $data): PaymentResponse
    {
        $card = $this->requireCard($data);

        $request = $this->accountData() + [
            'APIVersion' => self::API_VERSION,
            'HashPassword' => $this->hash($this->config->secretKey),
            'MerchantOrderId' => $data->orderId,
            'InstallmentCount' => $this->formatInstallment($data->installments()),
            'Amount' => $this->formatAmount($data->amount),
            'FECCurrencyCode' => Currency::numeric($data->currency),
            'CurrencyCode' => Currency::numeric($data->currency),
            'TransactionSecurity' => '1',
            'CardNumber' => $card->number,
            'CardExpireDateYear' => $card->expireYearShort(),
            'CardExpireDateMonth' => $card->expireMonth,
            'CardCVV2' => $card->cvv,
            'CardHolderName' => $card->holderName ?? '',
        ];

        $request['HashData'] = $this->createHash($request);

        $response = $this->postXml('Non3DPayGate', $request);
        $approved = $this->pick($response, ['ResponseCode']) === self::SUCCESS_CODE;

        return new PaymentResponse(
            success: $approved,
            paymentId: $this->pick($response, ['OrderId']) ?? $data->orderId,
            errorMessage: $approved ? null : $this->pick($response, ['ResponseMessage']),
            raw: $response,
            errorCode: $approved ? null : $this->pick($response, ['ResponseCode']),
        );
    }

    /**
     * Tam veya kısmi iade.
     *
     * Bankanın işlem numarasını `metadata['remote_order_id']` ile geçin.
     */
    public function refund(RefundPaymentData $data): RefundResponse
    {
        $operation = $data->amount !== null ? 'PartialDrawBack' : 'DrawBack';

        return $this->mapReversal($this->postXml($operation, $this->reversalRequest($data)));
    }

    /**
     * Gün sonu öncesi işlem iptali.
     */
    public function cancel(RefundPaymentData $data): RefundResponse
    {
        return $this->mapReversal($this->postXml('SaleReversal', $this->reversalRequest($data)));
    }

    /**
     * @return array<string, mixed>
     */
    protected function reversalRequest(RefundPaymentData $data): array
    {
        $request = $this->accountData() + [
            'HashPassword' => $this->hash($this->config->secretKey),
            'MerchantOrderId' => $data->paymentId,
            'OrderId' => (string) ($data->meta('remote_order_id') ?? ''),
        ];

        if ($data->amount !== null) {
            $request['Amount'] = $this->formatAmount($data->amount);
        }

        $request['HashData'] = $this->createHash($request);

        return $request;
    }

    /**
     * @param  array<string, mixed>  $response
     */
    protected function mapReversal(array $response): RefundResponse
    {
        $approved = $this->pick($response, ['ResponseCode']) === self::SUCCESS_CODE;

        return new RefundResponse(
            success: $approved,
            refundId: $this->pick($response, ['OrderId']),
            errorMessage: $approved ? null : $this->pick($response, ['ResponseMessage']),
            raw: $response,
        );
    }

    /**
     * Kuveyt Türk ile aynı imza şeması.
     *
     * @param  array<string, mixed>  $request
     */
    protected function createHash(array $request): string
    {
        return $this->hash(implode('', [
            (string) ($request['MerchantId'] ?? ''),
            (string) ($request['MerchantOrderId'] ?? ''),
            (string) ($request['Amount'] ?? ''),
            (string) ($request['OkUrl'] ?? ''),
            (string) ($request['FailUrl'] ?? ''),
            (string) ($request['UserName'] ?? ''),
            $this->hash($this->config->secretKey),
        ]));
    }

    protected function hash(string $value): string
    {
        return base64_encode(hash('sha1', $value, true));
    }

    /**
     * Vakıf Katılım tutarları kuruş cinsinden tam sayı olarak bekler.
     */
    protected function formatAmount(float $amount): string
    {
        return $this->amountInMinorUnits($amount);
    }

    protected function formatInstallment(int $installment): string
    {
        return $installment > 1 ? (string) $installment : '0';
    }

    /**
     * @return array<string, string>
     */
    protected function accountData(): array
    {
        return [
            'MerchantId' => $this->config->merchantId,
            'CustomerId' => (string) ($this->config->extra('customer_id') ?? $this->config->terminalId),
            'UserName' => $this->config->username,
            'SubMerchantId' => (string) ($this->config->extra('sub_merchant_id') ?? '0'),
        ];
    }

    protected function operationUrl(string $operation): string
    {
        return rtrim($this->config->endpoint('payment_api'), '/').'/'.$operation;
    }

    /**
     * @param  array<string, mixed>  $request
     * @return array<string, mixed>
     */
    protected function postXml(string $operation, array $request): array
    {
        return $this->client->postXml(
            url: $this->operationUrl($operation),
            data: $request,
            root: self::XML_ROOT,
            encoding: 'ISO-8859-1',
        );
    }
}
