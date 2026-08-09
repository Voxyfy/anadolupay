<?php

declare(strict_types=1);

namespace Voxyfy\AnadoluPay\Gateways\Bank;

use Voxyfy\AnadoluPay\Contracts\SupportsCancellation;
use Voxyfy\AnadoluPay\Contracts\SupportsStatusQuery;
use Voxyfy\AnadoluPay\DTO\CreatePaymentData;
use Voxyfy\AnadoluPay\DTO\PaymentResponse;
use Voxyfy\AnadoluPay\DTO\RefundPaymentData;
use Voxyfy\AnadoluPay\DTO\RefundResponse;
use Voxyfy\AnadoluPay\DTO\StatusResponse;
use Voxyfy\AnadoluPay\DTO\VerificationResponse;
use Voxyfy\AnadoluPay\DTO\VerifyPaymentData;
use Voxyfy\AnadoluPay\Exceptions\PaymentFailedException;
use Voxyfy\AnadoluPay\Support\Bank\OrderStatus;
use Voxyfy\AnadoluPay\Support\Bank\Xml;
use Voxyfy\AnadoluPay\Support\Money;

/**
 * Kuveyt Türk Sanal POS (BOA / TDV2.0) Driver'ı
 *
 * Kuveyt Türk'ün akışı iki noktada farklıdır:
 *
 *   1. 3D adımında bankaya XML gönderilir ve banka, POST edilecek form
 *      alanları yerine doğrudan tarayıcıya basılacak bir HTML sayfası döner.
 *      Bu sayfa `PaymentResponse::$htmlContent` içinde taşınır.
 *   2. Dönüşte gelen `AuthenticationResponse` alanı URL kodlanmış bir XML
 *      belgesidir; önce çözülür, sonra provizyon isteği yapılır.
 *
 * İmza: sha1 + base64. Şifre önce tek başına hash'lenir, sonra
 * MerchantId + MerchantOrderId + Amount + OkUrl + FailUrl + UserName +
 * hash'lenmiş şifre ayraçsız birleştirilip tekrar hash'lenir.
 */
class KuveytPosGateway extends AbstractBankGateway implements SupportsCancellation, SupportsStatusQuery
{
    /** Başarılı işlem kodu. */
    protected const SUCCESS_CODE = '00';

    /** BOA API sürümü. */
    protected const API_VERSION = 'TDV2.0.0';

    /** İsteklerin XML kök elemanı. */
    protected const XML_ROOT = 'KuveytTurkVPosMessage';

    /** Kuveyt Türk para birimlerini 4 haneli kodla bekler. */
    protected const CURRENCIES = [
        'TRY' => '0949',
        'USD' => '0840',
        'EUR' => '0978',
    ];

    /** Kart markası adları. */
    protected const CARD_TYPES = [
        'visa' => 'Visa',
        'mastercard' => 'MasterCard',
        'troy' => 'Troy',
    ];

    /**
     * 3D adımında banka hazır HTML döndürür.
     */
    protected function performCreatePayment(CreatePaymentData $data): PaymentResponse
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
     * 3D kayıt (enrollment) isteği gövdesi.
     *
     * @return array<string, mixed>
     */
    protected function build3dFormFields(CreatePaymentData $data): array
    {
        $this->config->require(['merchantId', 'username', 'secretKey']);

        $card = $this->requireCard($data);
        $customer = $data->customer;

        /** @var array<string, mixed> $billing */
        $billing = is_array($customer['billing_address'] ?? null) ? $customer['billing_address'] : [];

        $request = $this->accountData() + [
            'APIVersion' => self::API_VERSION,
            'HashData' => '',
            'TransactionType' => 'Sale',
            'TransactionSecurity' => '3',
            'InstallmentCount' => $this->formatInstallment($data->installments()),
            'Amount' => $this->formatAmount($data->money()),
            // DisplayAmount, Amount ile aynı olmalıdır.
            'DisplayAmount' => $this->formatAmount($data->money()),
            'CurrencyCode' => $this->currencyCode($data->currency),
            'MerchantOrderId' => $data->orderId,
            'OkUrl' => $this->successUrl($data),
            'FailUrl' => $this->failUrl($data),
            'DeviceData' => [
                'ClientIP' => $data->clientIp(),
                // 03 => Web tarayıcı
                'DeviceChannel' => (string) ($customer['payment_channel'] ?? '03'),
            ],
            'CardHolderData' => [
                'BillAddrCity' => (string) ($billing['city'] ?? $customer['city'] ?? ''),
                'BillAddrCountry' => (string) ($billing['country'] ?? $customer['country'] ?? ''),
                'BillAddrLine1' => (string) ($billing['address'] ?? $customer['address'] ?? ''),
                'BillAddrPostCode' => (string) ($billing['zip_code'] ?? $customer['zipCode'] ?? ''),
                'BillAddrState' => (string) ($billing['state'] ?? ''),
                'Email' => (string) ($customer['email'] ?? ''),
                'MobilePhone' => [
                    'Cc' => (string) ($customer['gsm_number_cc'] ?? '90'),
                    'Subscriber' => (string) ($customer['gsm_number'] ?? $customer['phone'] ?? ''),
                ],
            ],
            'CardHolderName' => $card->holderName ?? '',
            'CardType' => $this->cardType($card->type),
            'CardNumber' => $card->number,
            'CardExpireDateYear' => $card->expireYearShort(),
            'CardExpireDateMonth' => $card->expireMonth,
            'CardCVV2' => $card->cvv,
        ];

        $request['HashData'] = $this->createHash($request);

        return $request;
    }

    /**
     * Banka dönüşünü çözer ve provizyonu tamamlar.
     */
    protected function performVerify(VerifyPaymentData $data): VerificationResponse
    {
        $encoded = $this->pick($data->payload, ['AuthenticationResponse']);

        if ($encoded === null) {
            return new VerificationResponse(
                success: false,
                paymentId: $this->extractOrderId($data->payload),
                status: 'failed',
                raw: ['callback' => $data->payload],
            );
        }

        $authentication = Xml::decode(urldecode($encoded));

        if (! $this->is3dAuthSuccess($authentication)) {
            return new VerificationResponse(
                success: false,
                paymentId: $this->extractOrderId($authentication),
                status: 'failed',
                raw: ['callback' => $authentication],
            );
        }

        return $this->mapProvisionResponse(
            $authentication,
            $this->provision($authentication),
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    protected function provision(array $payload): array
    {
        /** @var array<string, mixed> $vposMessage */
        $vposMessage = is_array($payload['VPosMessage'] ?? null) ? $payload['VPosMessage'] : [];

        $request = $this->accountData() + [
            'APIVersion' => self::API_VERSION,
            'HashData' => '',
            'CustomerIPAddress' => (string) ($vposMessage['CustomerIPAddress'] ?? '127.0.0.1'),
            'KuveytTurkVPosAdditionalData' => [
                'AdditionalData' => [
                    'Key' => 'MD',
                    'Data' => $this->pick($payload, ['MD'], '') ?? '',
                ],
            ],
            'TransactionType' => 'Sale',
            'InstallmentCount' => (string) ($vposMessage['InstallmentCount'] ?? '0'),
            'Amount' => (string) ($vposMessage['Amount'] ?? ''),
            'DisplayAmount' => (string) ($vposMessage['Amount'] ?? ''),
            'CurrencyCode' => (string) ($vposMessage['CurrencyCode'] ?? ''),
            'MerchantOrderId' => (string) ($vposMessage['MerchantOrderId'] ?? ''),
            'TransactionSecurity' => (string) ($vposMessage['TransactionSecurity'] ?? '3'),
        ];

        $request['HashData'] = $this->createHash($request);

        return $this->postXml($request);
    }

    /**
     * 3D'siz doğrudan provizyon.
     */
    protected function nonSecurePayment(CreatePaymentData $data): PaymentResponse
    {
        $card = $this->requireCard($data);

        $request = $this->accountData() + [
            'APIVersion' => self::API_VERSION,
            'HashData' => '',
            'TransactionType' => 'Sale',
            'TransactionSecurity' => '1',
            'MerchantOrderId' => $data->orderId,
            'Amount' => $this->formatAmount($data->money()),
            'DisplayAmount' => $this->formatAmount($data->money()),
            'CurrencyCode' => $this->currencyCode($data->currency),
            'InstallmentCount' => $this->formatInstallment($data->installments()),
            'CardHolderName' => $card->holderName ?? '',
            'CardNumber' => $card->number,
            'CardExpireDateYear' => $card->expireYearShort(),
            'CardExpireDateMonth' => $card->expireMonth,
            'CardCVV2' => $card->cvv,
        ];

        $request['HashData'] = $this->createHash($request);

        $response = $this->postXml($request);
        $approved = $this->responseCode($response) === self::SUCCESS_CODE;

        return new PaymentResponse(
            success: $approved,
            paymentId: $this->pick($response, ['OrderId', 'MerchantOrderId']) ?? $data->orderId,
            errorMessage: $approved ? null : $this->pick($response, ['ResponseMessage']),
            raw: $response,
            errorCode: $approved ? null : $this->responseCode($response),
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function checkCallbackHash(array $payload): bool
    {
        // Kuveyt Türk dönüşte doğrulanacak bir hash göndermez;
        // güvenlik `AuthenticationResponse` içeriğiyle sağlanır.
        return true;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function is3dAuthSuccess(array $payload): bool
    {
        return $this->responseCode($payload) === self::SUCCESS_CODE;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function mapCallbackResponse(array $payload): VerificationResponse
    {
        $approved = $this->responseCode($payload) === self::SUCCESS_CODE;

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
        $approved = $this->responseCode($provision) === self::SUCCESS_CODE;

        return new VerificationResponse(
            success: $approved,
            paymentId: $this->extractOrderId($provision) ?? $this->extractOrderId($payload),
            status: $approved ? 'success' : 'failed',
            raw: ['callback' => $payload, 'provision' => $provision],
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function extractOrderId(array $payload): ?string
    {
        $vposMessage = $payload['VPosMessage'] ?? [];

        if (is_array($vposMessage)) {
            $orderId = $this->pick($vposMessage, ['MerchantOrderId', 'OrderId']);

            if ($orderId !== null) {
                return $orderId;
            }
        }

        return $this->pick($payload, ['MerchantOrderId', 'OrderId']);
    }

    /**
     * İmza: sha1_b64( MerchantId + MerchantOrderId + Amount + OkUrl +
     * FailUrl + UserName + sha1_b64(password) ).
     *
     * @param  array<string, mixed>  $request
     */
    protected function createHash(array $request): string
    {
        $hashedPassword = $this->hash($this->config->secretKey);

        return $this->hash(implode('', [
            (string) ($request['MerchantId'] ?? ''),
            (string) ($request['MerchantOrderId'] ?? ''),
            (string) ($request['Amount'] ?? ''),
            (string) ($request['OkUrl'] ?? ''),
            (string) ($request['FailUrl'] ?? ''),
            (string) ($request['UserName'] ?? ''),
            $hashedPassword,
        ]));
    }

    protected function hash(string $value): string
    {
        return base64_encode(hash('sha1', $value, true));
    }

    /**
     * Kuveyt Türk tutarları kuruş cinsinden tam sayı olarak bekler.
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
                message: sprintf("Kuveyt Türk '%s' para birimini desteklemiyor.", $currency),
                context: ['supported' => array_keys(self::CURRENCIES)],
            );
        }

        return $code;
    }

    protected function cardType(?string $type): string
    {
        return self::CARD_TYPES[strtolower((string) $type)] ?? '';
    }

    /**
     * @param  array<string, mixed>  $response
     */
    protected function responseCode(array $response): ?string
    {
        return $this->pick($response, ['ResponseCode']);
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
        ];
    }

    /**
     * BOA işlemleri ayrı uçlara gider; taban adrese işlem adı eklenir.
     *
     * Yapılandırmadaki adres zaten işlem adıyla bitiyorsa (eski
     * kurulumlarla uyum için) olduğu gibi kullanılır. Taban adrese POST
     * etmek bankadan `AssemblyNotFound` yanıtı aldırır — istek işlenir ama
     * çağrılan metot bulunamaz.
     */
    protected function operationUrl(string $operation): string
    {
        $base = rtrim($this->config->endpoint('payment_api'), '/');

        return str_ends_with($base, $operation) ? $base : $base.'/'.$operation;
    }

    /**
     * @param  array<string, mixed>  $request
     * @return array<string, mixed>
     */
    protected function postXml(array $request, string $operation = 'ThreeDModelProvisionGate'): array
    {
        return $this->client->postXml(
            url: $this->operationUrl($operation),
            data: $request,
            root: self::XML_ROOT,
            encoding: 'ISO-8859-1',
        );
    }

    /**
     * Sipariş durumunu sorgular.
     *
     * Kuveyt Türk sorgu, iade ve iptal işlemlerini ödeme ucundan değil
     * ayrı bir BOA servisinden (`query_api`) yürütür. İstek gövdesi de
     * farklıdır: `VPosMessage` bloğu bir zarfın içine sarılır.
     */
    public function status(string $orderId, array $context = []): StatusResponse
    {
        $response = $this->postQuery('GetMerchantOrderDetail', $this->queryEnvelope(
            operation: 'GetMerchantOrderDetail',
            orderId: $orderId,
            context: $context,
        ));

        $order = $response['Value']['OrderContract'] ?? $response['OrderContract'] ?? null;

        if (! is_array($order)) {
            return StatusResponse::notFound($orderId, $response);
        }

        return new StatusResponse(
            found: true,
            status: OrderStatus::map($this->pick($order, ['LastOrderStatus', 'OrderStatus']), OrderStatus::BOA),
            orderId: $orderId,
            paymentId: $this->pick($order, ['OrderId', 'ProvisionNumber']),
            amount: ($amount = $this->pick($order, ['FEC', 'Amount'])) !== null && is_numeric($amount)
                ? Money::fromMinorUnits((int) $amount)
                : null,
            installment: ($i = $this->pick($order, ['InstallmentCount'])) !== null ? (int) $i : null,
            transactionTime: $this->pick($order, ['OrderDate', 'TransactionDate']),
            maskedCardNumber: $this->pick($order, ['MaskedPAN', 'MaskedCardNumber']),
            raw: $response,
        );
    }

    /**
     * Gün sonu öncesi işlem iptali (`SaleReversal`).
     *
     * Kuveyt Türk iptali sipariş numarasıyla değil kendi referanslarıyla
     * eşler; bunları ödeme yanıtından saklayıp `metadata` ile geçin:
     * `remote_order_id`, `ref_ret_num`, `auth_code`, `transaction_id`.
     */
    public function cancel(RefundPaymentData $data): RefundResponse
    {
        return $this->mapReversal($this->postQuery('SaleReversal', $this->reversalEnvelope('SaleReversal', $data)));
    }

    /**
     * Tam veya kısmi iade (`DrawBack` / `PartialDrawback`).
     */
    protected function performRefund(RefundPaymentData $data): RefundResponse
    {
        $operation = $data->money() !== null ? 'PartialDrawback' : 'DrawBack';

        return $this->mapReversal($this->postQuery($operation, $this->reversalEnvelope($operation, $data)));
    }

    /**
     * Sorgu isteğinin zarfı.
     *
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    protected function queryEnvelope(string $operation, string $orderId, array $context): array
    {
        $vposMessage = $this->accountData() + [
            'APIVersion' => self::API_VERSION,
            'InstallmentMaturityCommisionFlag' => 0,
            'HashData' => '',
            'SubMerchantId' => 0,
            'BatchID' => 0,
            'TransactionType' => $operation,
            'InstallmentCount' => 0,
            'Amount' => 0,
            'DisplayAmount' => 0,
            'CancelAmount' => 0,
            'MerchantOrderId' => $orderId,
            'CurrencyCode' => $this->currencyCode((string) ($context['currency'] ?? 'TRY')),
            'FECAmount' => 0,
            'QeryId' => 0,
            'DebtId' => 0,
            'SurchargeAmount' => 0,
            'SGKDebtAmount' => 0,
            'TransactionSecurity' => 1,
        ];

        $vposMessage['HashData'] = $this->createHash($vposMessage);

        return [
            'IsFromExternalNetwork' => true,
            'BusinessKey' => 0,
            'ResourceId' => 0,
            'ActionId' => 0,
            'LanguageId' => 0,
            'CustomerId' => (string) ($this->config->extra('customer_id') ?? $this->config->terminalId),
            'MailOrTelephoneOrder' => true,
            'Amount' => 0,
            'MerchantId' => $this->config->merchantId,
            'MerchantOrderId' => $orderId,
            'OrderId' => (string) ($context['remote_order_id'] ?? 0),
            'TransactionType' => 0,
            'VPosMessage' => $vposMessage,
        ];
    }

    /**
     * İade/iptal isteğinin zarfı.
     *
     * @return array<string, mixed>
     */
    protected function reversalEnvelope(string $operation, RefundPaymentData $data): array
    {
        $amount = $data->money()?->toMinorUnitsString() ?? '0';

        $vposMessage = $this->accountData() + [
            'APIVersion' => self::API_VERSION,
            'InstallmentMaturityCommisionFlag' => 0,
            'HashData' => '',
            'SubMerchantId' => 0,
            'BatchID' => 0,
            'TransactionType' => $operation,
            'InstallmentCount' => 0,
            'Amount' => $amount,
            'DisplayAmount' => $amount,
            'CancelAmount' => $amount,
            'MerchantOrderId' => $data->paymentId,
            'FECAmount' => 0,
            'CurrencyCode' => $this->currencyCode($data->currency),
            'QeryId' => 0,
            'DebtId' => 0,
            'SurchargeAmount' => 0,
            'SGKDebtAmount' => 0,
            'TransactionSecurity' => 1,
        ];

        $vposMessage['HashData'] = $this->createHash($vposMessage);

        return [
            'IsFromExternalNetwork' => true,
            'BusinessKey' => 0,
            'ResourceId' => 0,
            'ActionId' => 0,
            'LanguageId' => 0,
            'CustomerId' => (string) ($this->config->extra('customer_id') ?? $this->config->terminalId),
            'MailOrTelephoneOrder' => true,
            'Amount' => $amount,
            'MerchantId' => $this->config->merchantId,
            'OrderId' => (string) ($data->meta('remote_order_id') ?? ''),
            'RRN' => (string) ($data->meta('ref_ret_num') ?? ''),
            'Stan' => (string) ($data->meta('transaction_id') ?? ''),
            'ProvisionNumber' => (string) ($data->meta('auth_code') ?? ''),
            'VPosMessage' => $vposMessage,
        ];
    }

    /**
     * @param  array<string, mixed>  $response
     */
    protected function mapReversal(array $response): RefundResponse
    {
        $approved = $this->responseCode($response) === self::SUCCESS_CODE
            || $this->responseCode($response['Value'] ?? []) === self::SUCCESS_CODE;

        return new RefundResponse(
            success: $approved,
            refundId: $this->pick($response, ['OrderId', 'ProvisionNumber']),
            errorMessage: $approved ? null : $this->pick($response, ['ResponseMessage', 'ErrorMessage']),
            raw: $response,
        );
    }

    /**
     * BOA sorgu servisine istek gönderir.
     *
     * **Bilinen sınır:** bu uç (`VirtualPosService.svc/Basic`) bir WCF
     * `basicHttpBinding` servisidir ve JSON gövdeyi `415 Unsupported Media
     * Type` ile reddeder. Gerçek test terminalinde doğrulandı. Servisin
     * metadata yayını kapalı olduğu için SOAP sözleşmesi uzaktan
     * çıkarılamadı; doğru zarfı bankanın entegrasyon dokümanından alıp
     * uygulamak gerekir.
     *
     * Ödeme ve provizyon akışı bundan etkilenmez; yalnızca durum sorgusu,
     * iade ve iptal bu servisi kullanır.
     *
     * Ayrıca `query_api` tanımlanmazsa varsayılan **canlı** adrestir; test
     * terminaliyle çalışırken mutlaka test adresini verin.
     *
     * @param  array<string, mixed>  $request
     * @return array<string, mixed>
     */
    protected function postQuery(string $operation, array $request): array
    {
        return $this->client->postJson(
            url: rtrim($this->config->endpoint('query_api'), '/').'/'.$operation,
            data: ['request' => $request],
        );
    }
}
