<?php

declare(strict_types=1);

namespace Voxyfy\AnadoluPay\Gateways\Bank;

use Voxyfy\AnadoluPay\DTO\CardData;
use Voxyfy\AnadoluPay\DTO\CreatePaymentData;
use Voxyfy\AnadoluPay\DTO\PaymentResponse;
use Voxyfy\AnadoluPay\DTO\RefundPaymentData;
use Voxyfy\AnadoluPay\DTO\RefundResponse;
use Voxyfy\AnadoluPay\DTO\VerificationResponse;
use Voxyfy\AnadoluPay\DTO\VerifyPaymentData;
use Voxyfy\AnadoluPay\Exceptions\PaymentFailedException;
use Voxyfy\AnadoluPay\Support\Bank\Currency;
use Voxyfy\AnadoluPay\Support\Bank\Xml;
use Voxyfy\AnadoluPay\Support\Money;

/**
 * VakıfBank PayFlex V4 (MPI VPOS) Driver'ı
 *
 * Vakıfbank, Ziraat Bankası ve İş Bankası'nın PayFlex kurulumları bu
 * protokolü kullanır. Akış diğer bankalardan iki noktada ayrılır:
 *
 *   1. Kart bilgisi önce MPI'ya (`Enrollment.aspx`) gönderilir; banka kartın
 *      3D programına dâhil olup olmadığını (`VERes.Status`) ve doğrudan
 *      kartın ACS adresini (`ACSUrl`) döner. 3D formu bankaya değil, kartı
 *      çıkaran bankanın ACS adresine POST edilir.
 *   2. Doğrulama sonrası provizyon isteği kart bilgisini tekrar ister.
 *      Bu yüzden `verify()` çağrılırken kart, `order['card']` içinde
 *      yeniden verilmelidir.
 *
 * İstekler `VposRequest` kök elemanlı XML'in `prmstr` form alanı içinde
 * gönderilir. Hash kullanılmaz; kimlik doğrulama MerchantId + Password iledir.
 */
class PayFlexGateway extends AbstractBankGateway
{
    /** Başarılı işlem kodu. */
    protected const SUCCESS_CODE = '0000';

    /** Kart markası kodları. */
    protected const CARD_BRANDS = [
        'visa' => '100',
        'mastercard' => '200',
        'troy' => '300',
        'amex' => '400',
    ];

    /**
     * 3D formu MPI'dan alınan ACS bilgileriyle üretilir.
     */
    protected function performCreatePayment(CreatePaymentData $data): PaymentResponse
    {
        if ($data->paymentModel === CreatePaymentData::MODEL_NON_SECURE) {
            return $this->nonSecurePayment($data);
        }

        $enrollment = $this->enroll($data);

        return new PaymentResponse(
            success: true,
            paymentId: $data->orderId,
            raw: $enrollment['raw'],
            formAction: $enrollment['acs_url'],
            formFields: $enrollment['inputs'],
        );
    }

    /**
     * MPI kayıt durumu sorgusu (Enrollment).
     *
     * @return array{acs_url: string, inputs: array<string, scalar>, raw: array<string, mixed>}
     */
    protected function enroll(CreatePaymentData $data): array
    {
        $this->config->require(['merchantId', 'password']);

        $card = $this->requireCard($data);

        $request = [
            'MerchantId' => $this->config->merchantId,
            'MerchantPassword' => $this->config->password,
            'MerchantType' => (string) ($this->config->extra('merchant_type') ?? '0'),
            'PurchaseAmount' => $this->formatAmount($data->money()),
            'VerifyEnrollmentRequestId' => $this->randomString(),
            'Currency' => Currency::numeric($data->currency),
            'SuccessUrl' => $this->successUrl($data),
            'FailureUrl' => $this->failUrl($data),
            'Pan' => $card->number,
            'ExpiryDate' => $card->expiry('ym'),
            'BrandName' => $this->cardBrand($card->type),
            'IsRecurring' => 'false',
        ];

        if ($data->installments() > 1) {
            $request['InstallmentCount'] = (string) $data->installments();
        }

        $response = $this->client->postForm(
            url: $this->config->endpoint('gateway_3d'),
            fields: ['prmstr' => $this->encodeXml($request, 'VposEnrollmentRequest')],
        );

        $veres = $response['Message']['VERes'] ?? null;

        if (! is_array($veres)) {
            throw new PaymentFailedException(
                message: 'PayFlex MPI kayıt sorgusu beklenen yanıtı döndürmedi.',
                context: ['response' => $response],
            );
        }

        $status = (string) ($veres['Status'] ?? 'E');

        if ($status !== 'Y') {
            throw new PaymentFailedException(
                message: match ($status) {
                    'N' => 'Kart 3-D Secure programına dâhil değil.',
                    'U' => 'İşlem şu anda gerçekleştirilemiyor.',
                    default => (string) ($response['ErrorMessage'] ?? 'PayFlex MPI kayıt sorgusu başarısız.'),
                },
                context: [
                    'status' => $status,
                    'error_code' => $response['MessageErrorCode'] ?? null,
                    'response' => $response,
                ],
            );
        }

        return [
            'acs_url' => (string) ($veres['ACSUrl'] ?? ''),
            'inputs' => [
                'PaReq' => (string) ($veres['PaReq'] ?? ''),
                'TermUrl' => (string) ($veres['TermUrl'] ?? ''),
                'MD' => (string) ($veres['MD'] ?? ''),
            ],
            'raw' => $response,
        ];
    }

    /**
     * ACS dönüşünü doğrular ve provizyonu tamamlar.
     *
     * Provizyon isteği kart bilgisini gerektirdiği için sipariş bağlamında
     * kart yeniden verilmelidir:
     *
     *     new VerifyPaymentData($request->all(), order: [
     *         'id' => 'ORDER-1', 'amount' => 19.90, 'currency' => 'TRY',
     *         'card' => ['number' => '...', 'expire_month' => '01', ...],
     *     ]);
     */
    protected function performVerify(VerifyPaymentData $data): VerificationResponse
    {
        $payload = $data->payload;

        if (! $this->is3dAuthSuccess($payload)) {
            return new VerificationResponse(
                success: false,
                paymentId: $this->extractOrderId($payload),
                status: 'failed',
                raw: ['callback' => $payload],
            );
        }

        $card = $this->cardFromOrder($data);
        $orderId = (string) ($data->order('id') ?? $this->extractOrderId($payload) ?? '');
        $amount = $data->order('amount');
        $currency = (string) ($data->order('currency') ?? 'TRY');

        if ($orderId === '' || ! is_numeric($amount)) {
            throw new PaymentFailedException(
                message: "PayFlex provizyonu için sipariş bağlamı (order['id'], order['amount']) zorunludur.",
                context: ['bank' => $this->config->bank],
            );
        }

        $request = $this->accountData() + [
            'TransactionType' => 'Sale',
            'TransactionId' => $orderId,
            'CurrencyAmount' => $this->formatAmount(Money::of($amount, $currency)),
            'CurrencyCode' => Currency::numeric($currency),
            'ECI' => $this->pick($payload, ['Eci'], '') ?? '',
            'CAVV' => $this->pick($payload, ['Cavv'], '') ?? '',
            'MpiTransactionId' => $this->pick($payload, ['VerifyEnrollmentRequestId'], '') ?? '',
            'OrderId' => $orderId,
            'ClientIp' => (string) ($data->order('ip') ?? '127.0.0.1'),
            'TransactionDeviceSource' => '0',
            'CardHoldersName' => $card->holderName ?? '',
            'Cvv' => $card->cvv,
            'Pan' => $card->number,
            'Expiry' => $card->expiry('Ym'),
        ];

        $installment = (int) ($data->order('installment') ?? 1);

        if ($installment > 1) {
            $request['NumberOfInstallments'] = (string) $installment;
        }

        $provision = $this->postXml($request);

        return $this->mapProvisionResponse($payload, $provision);
    }

    /**
     * 3D'siz doğrudan provizyon.
     */
    protected function nonSecurePayment(CreatePaymentData $data): PaymentResponse
    {
        $card = $this->requireCard($data);

        $response = $this->postXml($this->accountData() + [
            'TransactionType' => 'Sale',
            'OrderId' => $data->orderId,
            'CurrencyAmount' => $this->formatAmount($data->money()),
            'CurrencyCode' => Currency::numeric($data->currency),
            'ClientIp' => $data->clientIp(),
            'TransactionDeviceSource' => '0',
            'Pan' => $card->number,
            'Expiry' => $card->expiry('Ym'),
            'Cvv' => $card->cvv,
        ]);

        $approved = $this->resultCode($response) === self::SUCCESS_CODE;

        return new PaymentResponse(
            success: $approved,
            paymentId: $this->pick($response, ['TransactionId']) ?? $data->orderId,
            errorMessage: $approved ? null : $this->pick($response, ['ResultDetail', 'ErrorMessage']),
            raw: $response,
            errorCode: $approved ? null : $this->resultCode($response),
        );
    }

    /**
     * İade işlemi.
     *
     * PayFlex iadeyi orijinal işlemin `TransactionId` değeriyle eşler;
     * bu değeri `metadata['transaction_id']` ile geçin.
     */
    protected function performRefund(RefundPaymentData $data): RefundResponse
    {
        $request = [
            'MerchantId' => $this->config->merchantId,
            'Password' => $this->config->password,
            'TransactionType' => 'Refund',
            'ReferenceTransactionId' => $this->referenceTransactionId($data),
            'ClientIp' => (string) ($data->meta('ip') ?? '127.0.0.1'),
        ];

        if (($amount = $data->money()) !== null) {
            $request['CurrencyAmount'] = $this->formatAmount($amount);
        }

        return $this->mapReversal($this->postXml($request));
    }

    /**
     * Gün sonu öncesi işlem iptali.
     */
    public function cancel(RefundPaymentData $data): RefundResponse
    {
        return $this->mapReversal($this->postXml([
            'MerchantId' => $this->config->merchantId,
            'Password' => $this->config->password,
            'TransactionType' => 'Cancel',
            'ReferenceTransactionId' => $this->referenceTransactionId($data),
            'ClientIp' => (string) ($data->meta('ip') ?? '127.0.0.1'),
        ]));
    }

    /**
     * @return array<string, scalar>
     */
    protected function build3dFormFields(CreatePaymentData $data): array
    {
        // PayFlex'te form alanları MPI yanıtından üretilir.
        return $this->enroll($data)['inputs'];
    }

    /**
     * PayFlex dönüşü hash içermez; MPI yanıtındaki `Status` alanı esastır.
     *
     * @param  array<string, mixed>  $payload
     */
    protected function checkCallbackHash(array $payload): bool
    {
        return true;
    }

    /**
     * PayFlex'te 'Y' tam 3D doğrulaması, 'A' yarı güvenli akıştır.
     *
     * @param  array<string, mixed>  $payload
     */
    protected function is3dAuthSuccess(array $payload): bool
    {
        return $this->pick($payload, ['Status']) === 'Y';
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    protected function provision(array $payload): array
    {
        // verify() kendi akışını yürütür.
        return [];
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
        $approved = $this->resultCode($provision) === self::SUCCESS_CODE;

        return new VerificationResponse(
            success: $approved,
            paymentId: $this->pick($provision, ['TransactionId']) ?? $this->extractOrderId($payload),
            status: $approved ? 'success' : 'failed',
            raw: ['callback' => $payload, 'provision' => $provision],
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function extractOrderId(array $payload): ?string
    {
        return $this->pick($payload, ['OrderId', 'TransactionId', 'MD']);
    }

    /**
     * @param  array<string, mixed>  $response
     */
    protected function mapReversal(array $response): RefundResponse
    {
        $approved = $this->resultCode($response) === self::SUCCESS_CODE;

        return new RefundResponse(
            success: $approved,
            refundId: $this->pick($response, ['TransactionId']),
            errorMessage: $approved ? null : $this->pick($response, ['ResultDetail', 'ErrorMessage']),
            raw: $response,
        );
    }

    protected function referenceTransactionId(RefundPaymentData $data): string
    {
        $reference = $data->meta('transaction_id');

        if (is_string($reference) && $reference !== '') {
            return $reference;
        }

        // Sipariş numarası ile işlem numarası aynıysa fallback olarak kullanılır.
        return $data->paymentId;
    }

    protected function cardFromOrder(VerifyPaymentData $data): CardData
    {
        $card = $data->order('card');

        if ($card instanceof CardData) {
            return $card;
        }

        if (is_array($card)) {
            return CardData::fromArray($card);
        }

        throw new PaymentFailedException(
            message: "PayFlex provizyonu kart bilgisi gerektirir; order['card'] sağlanmalıdır.",
            context: ['bank' => $this->config->bank],
        );
    }

    /**
     * @param  array<string, mixed>  $response
     */
    protected function resultCode(array $response): ?string
    {
        return $this->pick($response, ['ResultCode', 'ResponseCode']);
    }

    protected function cardBrand(?string $type): ?string
    {
        return self::CARD_BRANDS[strtolower((string) $type)] ?? null;
    }

    /**
     * @return array<string, string>
     */
    protected function accountData(): array
    {
        $this->config->require(['merchantId', 'password', 'terminalId']);

        return [
            'MerchantId' => $this->config->merchantId,
            'Password' => $this->config->password,
            'TerminalNo' => $this->config->terminalId,
        ];
    }

    /**
     * PayFlex XML'i `prmstr` form alanı içinde bekler.
     *
     * @param  array<string, mixed>  $request
     * @return array<string, mixed>
     */
    protected function postXml(array $request): array
    {
        return $this->client->postForm(
            url: $this->config->endpoint('payment_api'),
            fields: ['prmstr' => $this->encodeXml($request, 'VposRequest')],
        );
    }

    /**
     * @param  array<string, mixed>  $request
     */
    protected function encodeXml(array $request, string $root): string
    {
        return Xml::encode(
            data: $request,
            root: $root,
            withDeclaration: false,
        );
    }
}
