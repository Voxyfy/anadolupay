<?php

declare(strict_types=1);

namespace Voxyfy\AnadoluPay\Gateways\Provider;

use Voxyfy\AnadoluPay\Contracts\ProvidesWebhookAcknowledgement;
use Voxyfy\AnadoluPay\Contracts\SupportsBinQuery;
use Voxyfy\AnadoluPay\Contracts\SupportsCancellation;
use Voxyfy\AnadoluPay\Contracts\SupportsOrderHistory;
use Voxyfy\AnadoluPay\Contracts\SupportsPreAuthorization;
use Voxyfy\AnadoluPay\Contracts\SupportsStatusQuery;
use Voxyfy\AnadoluPay\DTO\BinResponse;
use Voxyfy\AnadoluPay\DTO\CapturePaymentData;
use Voxyfy\AnadoluPay\DTO\CreatePaymentData;
use Voxyfy\AnadoluPay\DTO\PaymentResponse;
use Voxyfy\AnadoluPay\DTO\RefundPaymentData;
use Voxyfy\AnadoluPay\DTO\RefundResponse;
use Voxyfy\AnadoluPay\DTO\StatusResponse;
use Voxyfy\AnadoluPay\DTO\VerificationResponse;
use Voxyfy\AnadoluPay\DTO\VerifyPaymentData;
use Voxyfy\AnadoluPay\Exceptions\PaymentFailedException;
use Voxyfy\AnadoluPay\Gateways\Bank\AbstractBankGateway;
use Voxyfy\AnadoluPay\Support\Bank\OrderStatus;
use Voxyfy\AnadoluPay\Support\Money;

/**
 * Moka United Driver'ı
 *
 * Moka JSON-POST üzerinden çalışır ve her istekte kimlik bilgilerini
 * `PaymentDealerAuthentication` bloğunda taşır:
 *
 *     CheckKey = sha256( DealerCode + "MK" + Username + "PD" + Password )
 *
 * Bu anahtar isteğe göre değişmez; sabittir. Yani CheckKey bir istek
 * imzası değil, bir kimlik doğrulama parolasıdır — gövdeyi korumaz.
 *
 * **3D dönüşünün tuhaflığı:** Moka ödemenin başarılı olup olmadığını ayrı
 * bir alanda bildirmez. `DoDirectPaymentThreeD` yanıtındaki `CodeForHash`
 * değerinin sonuna `T` (başarılı) veya `F` (başarısız) eklenip sha256'sı
 * alınır ve sonuç `hashValue` olarak POST edilir. Dönüşteki `resultCode`
 * başarılı işlemlerde **boş gelir**; sonucu okumanın tek yolu hash'tir.
 *
 * Bu yüzden `CodeForHash` ödeme başlatılırken saklanmalı ve `verify()`
 * çağrılırken `order['code_for_hash']` içinde geri verilmelidir:
 *
 *     $response = AnadoluPay::driver('moka')->createPayment($data);
 *     $codeForHash = $response->raw['code_for_hash'];   // siparişle birlikte saklayın
 *
 *     AnadoluPay::driver('moka')->verify(new VerifyPaymentData(
 *         $request->all(),
 *         order: ['code_for_hash' => $codeForHash],
 *     ));
 *
 * Tutarlar ondalık sayı olarak gönderilir (`27.50`), para birimi ISO kodu
 * değil Moka'nın kendi kısaltmasıdır (`TL`).
 *
 * Kaynak: https://developer.mokaunited.com — dokümantasyonun tamamı
 * herkese açıktır; hash algoritması oradaki test vektörüyle doğrulanmıştır.
 */
class MokaGateway extends AbstractBankGateway implements ProvidesWebhookAcknowledgement, SupportsBinQuery, SupportsCancellation, SupportsOrderHistory, SupportsPreAuthorization, SupportsStatusQuery
{
    /** İsteğin Moka tarafında işlenebildiğini bildiren kod. */
    protected const RESULT_SUCCESS = 'Success';

    /** Dışarıdan (API üzerinden) yapılan iptal/iade sebebi. */
    protected const REASON_EXTERNAL = 2;

    /**
     * 3D akışında Moka bankaya yönlendirilecek bir URL döner.
     */
    protected function performCreatePayment(CreatePaymentData $data): PaymentResponse
    {
        if ($data->paymentModel === CreatePaymentData::MODEL_NON_SECURE) {
            return $this->nonSecurePayment($data);
        }

        $response = $this->post('/PaymentDealer/DoDirectPaymentThreeD', $this->build3dFormFields($data));

        $url = $this->pick($response, ['Url']);
        $codeForHash = $this->pick($response, ['CodeForHash']);

        if ($url === null || $codeForHash === null) {
            throw new PaymentFailedException(
                message: 'Moka 3D ödeme bağlantısı alınamadı.',
                context: ['response' => $response],
            );
        }

        return new PaymentResponse(
            success: true,
            paymentId: $data->orderId,
            redirectUrl: $url,
            // CodeForHash saklanmalıdır; dönüş sonucunu doğrulamanın tek yolu.
            raw: $response + ['code_for_hash' => $codeForHash],
        );
    }

    /**
     * `DoDirectPaymentThreeD` istek gövdesi.
     *
     * Moka'ya form POST edilmediği için bu metot HTML form alanları değil,
     * API istek gövdesi döndürür.
     *
     * @return array<string, mixed>
     */
    protected function build3dFormFields(CreatePaymentData $data): array
    {
        return $this->paymentRequest($data) + [
            'ReturnHash' => 1,
            'RedirectUrl' => $this->successUrl($data),
            'RedirectType' => (int) $this->config->extra('redirect_type', 0),
        ];
    }

    /**
     * 3D'siz doğrudan çekim.
     */
    protected function nonSecurePayment(CreatePaymentData $data): PaymentResponse
    {
        $response = $this->post('/PaymentDealer/DoDirectPayment', $this->paymentRequest($data));
        $approved = $this->isBankApproved($response);

        return new PaymentResponse(
            success: $approved,
            paymentId: $this->pick($response, ['VirtualPosOrderId']) ?? $data->orderId,
            errorMessage: $approved ? null : $this->bankErrorMessage($response),
            raw: $response,
            errorCode: $approved ? null : $this->pick($response, ['ResultCode']),
        );
    }

    /**
     * Ortak ödeme isteği gövdesi.
     *
     * @return array<string, mixed>
     */
    protected function paymentRequest(CreatePaymentData $data): array
    {
        $card = $this->requireCard($data);

        $request = [
            'CardHolderFullName' => $card->holderName ?? '',
            'CardNumber' => $card->number,
            'ExpMonth' => $card->expireMonth,
            'ExpYear' => $card->expireYearLong(),
            'CvcNumber' => $card->cvv,
            'Amount' => $this->amount($data->money()),
            'Currency' => $this->currency($data->currency),
            // Peşin satışta 0 gönderilir; taksit 2-12 arasındadır.
            'InstallmentNumber' => $data->installments() > 1 ? $data->installments() : 0,
            'ClientIP' => $data->clientIp(),
            'OtherTrxCode' => $data->orderId,
            'IsPoolPayment' => (int) (bool) $this->config->extra('pool_payment', 0),
            'IsPreAuth' => $data->preAuthorization ? 1 : 0,
            'IsTokenized' => 0,
            'Software' => (string) $this->config->extra('software', 'anadolupay'),
        ];

        $buyer = $this->buyerInformation($data);

        if ($buyer !== []) {
            $request['BuyerInformation'] = $buyer;
        }

        return $request;
    }

    /**
     * Moka alıcı bilgisini zorunlu tutmaz ama ihtilaf hâlinde ister.
     *
     * @return array<string, string>
     */
    protected function buyerInformation(CreatePaymentData $data): array
    {
        $customer = $data->customer;

        $fields = array_filter([
            'BuyerFullName' => $customer['name'] ?? null,
            'BuyerEmail' => $customer['email'] ?? null,
            'BuyerGsmNumber' => $customer['phone'] ?? null,
            'BuyerAddress' => $customer['address'] ?? null,
        ], static fn (mixed $value): bool => is_scalar($value) && (string) $value !== '');

        return array_map(strval(...), $fields);
    }

    /**
     * Dönüş doğrulaması.
     *
     * `CodeForHash` dönüşte gelmediği için sipariş bağlamından alınır ve
     * hash kontrolüne dâhil edilmek üzere yüke eklenir.
     */
    protected function performVerify(VerifyPaymentData $data): VerificationResponse
    {
        $codeForHash = $data->order('code_for_hash');

        if (! is_string($codeForHash) || $codeForHash === '') {
            throw new PaymentFailedException(
                message: "Moka dönüşü için order['code_for_hash'] zorunludur; "
                    .'ödeme sonucu yalnızca bu değerden üretilen hash ile okunabilir.',
                context: ['bank' => $this->config->bank],
            );
        }

        return parent::performVerify(new VerifyPaymentData(
            payload: $data->payload + ['CodeForHash' => $codeForHash],
            headers: $data->headers,
            rawBody: $data->rawBody,
            order: $data->order,
        ));
    }

    /**
     * Dönüşteki `hashValue` iki geçerli değerden biri olmalıdır: başarı (`T`)
     * veya başarısızlık (`F`). Hiçbirine uymuyorsa yük değiştirilmiştir.
     *
     * @param  array<string, mixed>  $payload
     */
    protected function checkCallbackHash(array $payload): bool
    {
        $incoming = $this->pick($payload, ['hashValue']);
        $codeForHash = $this->pick($payload, ['CodeForHash']);

        if ($incoming === null || $codeForHash === null) {
            return false;
        }

        return hash_equals($this->resultHash($codeForHash, 'T'), $incoming)
            || hash_equals($this->resultHash($codeForHash, 'F'), $incoming);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function is3dAuthSuccess(array $payload): bool
    {
        $incoming = $this->pick($payload, ['hashValue']);
        $codeForHash = $this->pick($payload, ['CodeForHash']);

        if ($incoming === null || $codeForHash === null) {
            return false;
        }

        return hash_equals($this->resultHash($codeForHash, 'T'), $incoming);
    }

    /**
     * Moka provizyonu kendisi tamamlar; ikinci bir istek yoktur.
     *
     * @param  array<string, mixed>  $payload
     */
    protected function requiresProvision(array $payload): bool
    {
        return false;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    protected function provision(array $payload): array
    {
        return [];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function mapCallbackResponse(array $payload): VerificationResponse
    {
        return new VerificationResponse(
            success: true,
            // trxCode iptal ve iade işlemlerinde kullanılacak numaradır.
            paymentId: $this->pick($payload, ['trxCode']) ?? $this->extractOrderId($payload),
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
        return $this->mapCallbackResponse($payload);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function extractOrderId(array $payload): ?string
    {
        return $this->pick($payload, ['OtherTrxCode']);
    }

    /**
     * Ertesi gün ve sonrasındaki iadeler.
     *
     * Tutar verilmezse kalan tutarın tamamı iade edilir.
     */
    protected function performRefund(RefundPaymentData $data): RefundResponse
    {
        $request = $this->reference($data->paymentId);

        if (($amount = $data->money()) !== null) {
            $request['Amount'] = $this->amount($amount);
        }

        return $this->mapReversal($this->post('/PaymentDealer/DoCreateRefundRequest', $request));
    }

    /**
     * Aynı gün 22.00'ye kadar yapılabilen iptal.
     */
    public function cancel(RefundPaymentData $data): RefundResponse
    {
        $request = $this->reference($data->paymentId) + [
            'ClientIP' => (string) ($data->meta('ip') ?? '127.0.0.1'),
            'VoidRefundReason' => self::REASON_EXTERNAL,
        ];

        return $this->mapReversal($this->post('/PaymentDealer/DoVoid', $request));
    }

    /**
     * Ön provizyonu satışa dönüştürür.
     */
    public function capture(CapturePaymentData $data): PaymentResponse
    {
        $request = $this->reference($data->orderId) + [
            'ClientIP' => $data->clientIp(),
        ];

        if (($amount = $data->money()) !== null) {
            $request['Amount'] = $this->amount($amount);
        }

        $response = $this->post('/PaymentDealer/DoCapture', $request);
        $approved = $this->isBankApproved($response);

        return new PaymentResponse(
            success: $approved,
            paymentId: $this->pick($response, ['VirtualPosOrderId']) ?? $data->orderId,
            errorMessage: $approved ? null : $this->bankErrorMessage($response),
            raw: $response,
            errorCode: $approved ? null : $this->pick($response, ['ResultCode']),
        );
    }

    /**
     * Ödeme durumu.
     *
     * Moka'da durum iki alanın birleşimidir: `PaymentStatus` işlemin ne
     * olduğunu (ön provizyon / ödeme / iptal / iade), `TrxStatus` ise
     * başarılı olup olmadığını söyler. İkisi ayrı ayrı okunursa başarısız
     * bir ödeme "ödendi" görünür.
     */
    public function status(string $orderId, array $context = []): StatusResponse
    {
        $response = $this->post('/PaymentDealer/GetDealerPaymentTrxDetailList', $this->reference($orderId));

        $detail = $response['PaymentDetail'] ?? null;

        if (! is_array($detail) || $detail === []) {
            return StatusResponse::notFound($orderId, $response);
        }

        $currency = $this->currencyToIso($this->pick($detail, ['CurrencyCode']) ?? 'TL');
        $refunded = $this->pick($detail, ['RefAmount']);

        return new StatusResponse(
            found: true,
            status: $this->mapStatus($detail),
            orderId: $this->pick($detail, ['OtherTrxCode']) ?? $orderId,
            paymentId: $this->pick($detail, ['DealerPaymentId']),
            amount: ($amount = $this->pick($detail, ['Amount'])) !== null && is_numeric($amount)
                ? Money::fromDecimal($amount, $currency)
                : null,
            refundedAmount: $refunded !== null && is_numeric($refunded)
                ? Money::fromDecimal($refunded, $currency)
                : null,
            installment: ($installment = $this->pick($detail, ['InstallmentNumber'])) !== null
                ? (int) $installment
                : null,
            transactionTime: $this->pick($detail, ['PaymentDate']),
            maskedCardNumber: $this->maskedCard($detail),
            errorMessage: $this->pick($detail, ['ResultMessage']),
            raw: $response,
        );
    }

    /**
     * Ödemeye ait işlem (transaction) kayıtları.
     *
     * @return array<string, mixed>
     */
    public function orderHistory(string $orderId, array $context = []): array
    {
        return $this->post('/PaymentDealer/GetDealerPaymentTrxDetailList', $this->reference($orderId));
    }

    /**
     * BIN sorgusu. Moka kartın ilk 8 hanesini bekler.
     */
    public function binLookup(string $bin, array $context = []): BinResponse
    {
        $response = $this->post('/PaymentDealer/GetBankCardInformation', ['BinNumber' => $bin]);

        $bankName = $this->pick($response, ['BankName']);

        if ($bankName === null) {
            return new BinResponse(found: false, raw: $response);
        }

        $creditType = $this->pick($response, ['CreditType']);

        return new BinResponse(
            found: true,
            bankName: $bankName,
            brand: $this->pick($response, ['CardType']),
            // Moka CreditCard / DebitCard döner.
            type: $creditType !== null ? strtolower(str_replace('Card', '', $creditType)) : null,
            commercial: ($category = $this->pick($response, ['ProductCategory'])) !== null
                ? mb_strtolower($category) === 'ticari'
                : null,
            raw: $response,
        );
    }

    /**
     * Moka bildirimin işlendiğini yalnızca düz metin `OK` yanıtıyla kabul
     * eder; başka bir gövde gelirse bildirimi iki kez daha tekrarlar.
     */
    public function webhookAcknowledgement(bool $handled): string
    {
        return $handled ? 'OK' : '';
    }

    public function webhookAcknowledgementContentType(): string
    {
        return 'text/plain';
    }

    /**
     * `PaymentStatus` ve `TrxStatus` alanlarını tek bir duruma indirger.
     *
     * @param  array<string, mixed>  $detail
     */
    protected function mapStatus(array $detail): string
    {
        $paymentStatus = $this->pick($detail, ['PaymentStatus']);
        $trxStatus = $this->pick($detail, ['TrxStatus']);

        // Başarısız bir işlem, tipi ne olursa olsun başarısızdır.
        if ($trxStatus === '2') {
            return StatusResponse::STATUS_FAILED;
        }

        return OrderStatus::map($paymentStatus, OrderStatus::MOKA);
    }

    /**
     * İşlem referansı: Moka'nın kendi numarası ya da bizim sipariş numaramız.
     *
     * Moka sayısal olmayan referansları kendi `VirtualPosOrderId` biçiminde
     * beklemez; bu yüzden `ORDER-` ile başlayan değerler Moka'nın numarası,
     * diğerleri satıcı sipariş numarası (`OtherTrxCode`) sayılır.
     *
     * @return array<string, string>
     */
    protected function reference(string $id): array
    {
        return str_starts_with($id, 'ORDER-')
            ? ['VirtualPosOrderId' => $id, 'OtherTrxCode' => '']
            : ['VirtualPosOrderId' => '', 'OtherTrxCode' => $id];
    }

    /**
     * @param  array<string, mixed>  $response
     */
    protected function mapReversal(array $response): RefundResponse
    {
        $approved = $this->isBankApproved($response);

        return new RefundResponse(
            success: $approved,
            refundId: $this->pick($response, ['VirtualPosOrderId', 'RefundRequestId']),
            errorMessage: $approved ? null : $this->bankErrorMessage($response),
            raw: $response,
        );
    }

    /**
     * `CodeForHash` sonuna sonuç harfi eklenip sha256'lanır.
     *
     * Doküman kodun büyük harfli olmasını şart koşar.
     */
    protected function resultHash(string $codeForHash, string $result): string
    {
        return hash('sha256', strtoupper($codeForHash).$result);
    }

    /**
     * Moka tutarları ondalık sayı olarak bekler (`27.50`).
     *
     * Değer kuruş cinsinden tam sayıdan üretilir; ödeme yolunda float
     * aritmetiği yapılmaz.
     */
    protected function amount(Money $money): float
    {
        return (float) $money->toDecimalString();
    }

    /**
     * Moka para birimini ISO kodu yerine kendi kısaltmasıyla ister.
     */
    protected function currency(string $currency): string
    {
        return strtoupper($currency) === 'TRY' ? 'TL' : strtoupper($currency);
    }

    protected function currencyToIso(string $currency): string
    {
        return strtoupper($currency) === 'TL' ? 'TRY' : strtoupper($currency);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function isBankApproved(array $data): bool
    {
        return ($data['IsSuccessful'] ?? false) === true;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function bankErrorMessage(array $data): ?string
    {
        return $this->pick($data, ['ResultMessage']) ?? $this->pick($data, ['ResultCode']);
    }

    /**
     * @param  array<string, mixed>  $detail
     */
    protected function maskedCard(array $detail): ?string
    {
        $first = $this->pick($detail, ['CardNumberFirstSix']);
        $last = $this->pick($detail, ['CardNumberLastFour']);

        return $first !== null && $last !== null ? $first.'******'.$last : null;
    }

    /**
     * Kimlik bloğunu ekleyip isteği gönderir ve `Data` zarfını açar.
     *
     * @param  array<string, mixed>  $request
     * @return array<string, mixed>
     */
    protected function post(string $path, array $request): array
    {
        $this->config->require(['merchantId', 'username', 'password']);

        $response = $this->client->postJson(
            url: rtrim($this->config->endpoint('payment_api'), '/').$path,
            data: [
                'PaymentDealerAuthentication' => [
                    'DealerCode' => $this->config->merchantId,
                    'Username' => $this->config->username,
                    'Password' => $this->config->password,
                    'CheckKey' => $this->checkKey(),
                ],
                'PaymentDealerRequest' => $request,
            ],
        );

        $resultCode = $this->pick($response, ['ResultCode']);

        // Moka isteği hiç işleyemediyse Data null gelir ve hata kodu
        // dış zarfta durur; bunu "banka reddetti" ile karıştırmamak gerekir.
        if ($resultCode !== null && $resultCode !== self::RESULT_SUCCESS) {
            throw new PaymentFailedException(
                message: $this->pick($response, ['ResultMessage']) ?? $resultCode,
                context: ['bank' => $this->config->bank, 'result_code' => $resultCode],
            );
        }

        $data = $response['Data'] ?? null;

        return is_array($data) ? $data : $response;
    }

    /**
     * CheckKey = sha256( DealerCode + "MK" + Username + "PD" + Password )
     */
    protected function checkKey(): string
    {
        return hash('sha256', implode('', [
            $this->config->merchantId,
            'MK',
            $this->config->username,
            'PD',
            $this->config->password,
        ]));
    }
}
