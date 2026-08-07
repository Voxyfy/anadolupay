<?php

declare(strict_types=1);

namespace Voxyfy\AnadoluPay\Gateways\Bank;

use Voxyfy\AnadoluPay\DTO\CreatePaymentData;
use Voxyfy\AnadoluPay\DTO\PaymentResponse;
use Voxyfy\AnadoluPay\DTO\RefundPaymentData;
use Voxyfy\AnadoluPay\DTO\RefundResponse;
use Voxyfy\AnadoluPay\DTO\VerificationResponse;
use Voxyfy\AnadoluPay\DTO\VerifyPaymentData;
use Voxyfy\AnadoluPay\Exceptions\InvalidSignatureException;
use Voxyfy\AnadoluPay\Exceptions\PaymentFailedException;
use Voxyfy\AnadoluPay\Support\Bank\Currency;
use Voxyfy\AnadoluPay\Support\Money;

/**
 * Yapı Kredi PosNet Sanal POS Driver'ı
 *
 * PosNet'in 3D akışı diğer bankalardan farklıdır ve üç sunucu isteği içerir:
 *
 *   1. `oosRequestData` — kart ve sipariş bilgisi bankaya gönderilir, banka
 *      `data1`, `data2` ve `sign` paketlerini döner.
 *   2. Bu paketler 3D geçidine POST edilir, müşteri doğrulama yapar.
 *   3. Dönüşte gelen `MerchantPacket`/`BankPacket`/`Sign` üçlüsü
 *      `oosResolveMerchantData` ile çözülür, `mac` doğrulanır ve
 *      `oosTranData` ile provizyon tamamlanır.
 *
 * Tüm istekler `posnetRequest` kök elemanlı XML'in `xmldata` form alanı
 * içinde gönderilir. Hash algoritması sha256 + base64, ayraç `;`.
 */
class PosNetGateway extends AbstractBankGateway
{
    /** PosNet onaylı işlem için '1' döner. */
    protected const SUCCESS_CODE = '1';

    /** Sipariş numarasının sabit uzunluğu. */
    protected const ORDER_ID_LENGTH = 20;

    /** İade/iptal/durum sorgularında kullanılan uzunluk. */
    protected const ORDER_ID_TOTAL_LENGTH = 24;

    /** 3D Secure ile alınan siparişlerin ön eki. */
    protected const ORDER_ID_3D_PREFIX = 'TDSC';

    /**
     * 3D geçidine POST edilecek form alanları.
     *
     * Alanların bir kısmı bankadan `oosRequestData` isteğiyle alınır.
     *
     * @return array<string, scalar>
     */
    protected function build3dFormFields(CreatePaymentData $data): array
    {
        $this->config->require(['merchantId', 'terminalId', 'secretKey']);

        $card = $this->requireCard($data);

        $response = $this->postXml([
            'mid' => $this->config->merchantId,
            'tid' => $this->config->terminalId,
            'oosRequestData' => [
                'posnetid' => $this->posNetId(),
                'ccno' => $card->number,
                'expDate' => $card->expiry('ym'),
                'cvc' => $card->cvv,
                'amount' => $this->formatAmount($data->money()),
                'currencyCode' => Currency::numeric($data->currency),
                'installment' => $this->formatInstallment($data->installments()),
                'XID' => $this->formatOrderId($data->orderId),
                'cardHolderName' => $card->holderName ?? '',
                'tranType' => 'Sale',
            ],
        ]);

        $oos = $response['oosRequestDataResponse'] ?? null;

        if (! is_array($oos) || ! isset($oos['data1'], $oos['data2'], $oos['sign'])) {
            throw new PaymentFailedException(
                message: 'PosNet 3D başlatma isteği başarısız oldu.',
                context: ['response' => $response],
            );
        }

        return [
            'mid' => $this->config->merchantId,
            'posnetID' => $this->posNetId(),
            'posnetData' => (string) $oos['data1'],
            'posnetData2' => (string) $oos['data2'],
            'digest' => (string) $oos['sign'],
            'merchantReturnURL' => $this->successUrl($data),
            // Banka tarafından sağlanan posnet.js bu alanı doldurur.
            'url' => '',
            'lang' => $data->lang !== '' ? $data->lang : $this->config->lang,
        ];
    }

    /**
     * PosNet dönüşü doğrudan doğrulanamaz; önce `oosResolveMerchantData`
     * ile çözülmesi, ardından `mac` kontrolünden sonra `oosTranData` ile
     * provizyonun tamamlanması gerekir.
     */
    public function verify(VerifyPaymentData $data): VerificationResponse
    {
        $payload = $data->payload;

        $resolveResponse = $this->postXml([
            'mid' => $this->config->merchantId,
            'tid' => $this->config->terminalId,
            'oosResolveMerchantData' => [
                'bankData' => $this->pick($payload, ['BankPacket'], '') ?? '',
                'merchantData' => $this->pick($payload, ['MerchantPacket'], '') ?? '',
                'sign' => $this->pick($payload, ['Sign'], '') ?? '',
                'mac' => $this->pick($payload, ['mac'], '') ?? '',
            ],
        ]);

        $resolved = $resolveResponse['oosResolveMerchantDataResponse'] ?? null;

        if (! is_array($resolved)) {
            return new VerificationResponse(
                success: false,
                paymentId: $this->extractOrderId($payload),
                status: 'failed',
                raw: ['callback' => $payload, 'resolve' => $resolveResponse],
            );
        }

        /** @var array<string, mixed> $resolved */
        if ($this->config->verifyHash && ! $this->checkResolveMac($resolved)) {
            throw new InvalidSignatureException($this->config->bank, [
                'reason' => 'mac_mismatch',
                'order_id' => $this->pick($resolved, ['xid']),
            ]);
        }

        if (! $this->is3dAuthSuccess($resolved)) {
            return new VerificationResponse(
                success: false,
                paymentId: $this->pick($resolved, ['xid']),
                status: 'failed',
                raw: ['callback' => $payload, 'resolve' => $resolveResponse],
            );
        }

        $provision = $this->provisionFromResolved($payload, $resolved);
        $approved = $this->pick($provision, ['approved']) === self::SUCCESS_CODE;

        return new VerificationResponse(
            success: $approved,
            paymentId: $this->hostLogKey($provision) ?? $this->pick($resolved, ['xid']),
            status: $approved ? 'success' : 'failed',
            raw: [
                'callback' => $payload,
                'resolve' => $resolveResponse,
                'provision' => $provision,
            ],
        );
    }

    /**
     * `oosResolveMerchantData` yanıtındaki `mac` değerini doğrular.
     *
     * mac = sha256_b64( mdStatus;xid;amount;currency;mid;securityData )
     *
     * @param  array<string, mixed>  $resolved
     */
    protected function checkResolveMac(array $resolved): bool
    {
        $incoming = $this->pick($resolved, ['mac']);

        if ($incoming === null) {
            return false;
        }

        $expected = $this->hash(implode(';', [
            $this->pick($resolved, ['mdStatus'], '') ?? '',
            $this->pick($resolved, ['xid'], '') ?? '',
            $this->pick($resolved, ['amount'], '') ?? '',
            $this->pick($resolved, ['currency'], '') ?? '',
            $this->config->merchantId,
            $this->securityData(),
        ]));

        return hash_equals($expected, $incoming);
    }

    /**
     * Provizyon (`oosTranData`) isteği.
     *
     * mac = sha256_b64( orderId;amount;currency;mid;securityData )
     *
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $resolved
     * @return array<string, mixed>
     */
    protected function provisionFromResolved(array $payload, array $resolved): array
    {
        $mac = $this->hash(implode(';', [
            $this->pick($resolved, ['xid'], '') ?? '',
            $this->pick($resolved, ['amount'], '') ?? '',
            $this->pick($resolved, ['currency'], '') ?? '',
            $this->config->merchantId,
            $this->securityData(),
        ]));

        return $this->postXml([
            'mid' => $this->config->merchantId,
            'tid' => $this->config->terminalId,
            'oosTranData' => [
                'bankData' => $this->pick($payload, ['BankPacket'], '') ?? '',
                'merchantData' => $this->pick($payload, ['MerchantPacket'], '') ?? '',
                'sign' => $this->pick($payload, ['Sign'], '') ?? '',
                'wpAmount' => 0,
                'mac' => $mac,
            ],
        ]);
    }

    /**
     * 3D'siz doğrudan provizyon.
     */
    protected function nonSecurePayment(CreatePaymentData $data): PaymentResponse
    {
        $card = $this->requireCard($data);

        $response = $this->postXml([
            'mid' => $this->config->merchantId,
            'tid' => $this->config->terminalId,
            'tranDateRequired' => '1',
            'sale' => [
                'orderID' => $this->formatOrderId($data->orderId),
                'installment' => $this->formatInstallment($data->installments()),
                'amount' => $this->formatAmount($data->money()),
                'currencyCode' => Currency::numeric($data->currency),
                'ccno' => $card->number,
                'expDate' => $card->expiry('ym'),
                'cvc' => $card->cvv,
            ],
        ]);

        $approved = $this->pick($response, ['approved']) === self::SUCCESS_CODE;

        return new PaymentResponse(
            success: $approved,
            paymentId: $this->hostLogKey($response) ?? $data->orderId,
            errorMessage: $approved ? null : $this->pick($response, ['respText']),
            raw: $response,
            errorCode: $approved ? null : $this->pick($response, ['respCode']),
        );
    }

    /**
     * İade işlemi.
     *
     * PosNet iadeyi tercihen `hostLogKey` ile eşler; verilmezse sipariş
     * numarası kullanılır (3D siparişlerde 'TDSC' ön ekiyle).
     */
    public function refund(RefundPaymentData $data): RefundResponse
    {
        $transaction = [
            'amount' => $this->formatAmount($data->money() ?? Money::fromMinorUnits(0, $data->currency)),
            'currencyCode' => Currency::numeric($data->currency),
        ];

        $transaction += $this->reversalReference($data);

        $response = $this->postXml([
            'mid' => $this->config->merchantId,
            'tid' => $this->config->terminalId,
            'tranDateRequired' => '1',
            'return' => $transaction,
        ]);

        $approved = $this->pick($response, ['approved']) === self::SUCCESS_CODE;

        return new RefundResponse(
            success: $approved,
            refundId: $this->hostLogKey($response),
            errorMessage: $approved ? null : $this->pick($response, ['respText']),
            raw: $response,
        );
    }

    /**
     * Gün sonu öncesi işlem iptali.
     */
    public function cancel(RefundPaymentData $data): RefundResponse
    {
        $transaction = ['transaction' => 'sale'] + $this->reversalReference($data);

        $authCode = $data->meta('auth_code');

        if (is_string($authCode) && $authCode !== '') {
            $transaction['authCode'] = $authCode;
        }

        $response = $this->postXml([
            'mid' => $this->config->merchantId,
            'tid' => $this->config->terminalId,
            'tranDateRequired' => '1',
            'reverse' => $transaction,
        ]);

        $approved = $this->pick($response, ['approved']) === self::SUCCESS_CODE;

        return new RefundResponse(
            success: $approved,
            refundId: $this->hostLogKey($response),
            errorMessage: $approved ? null : $this->pick($response, ['respText']),
            raw: $response,
        );
    }

    /**
     * İade/iptal isteğinde işlem referansını belirler.
     *
     * @return array<string, string>
     */
    protected function reversalReference(RefundPaymentData $data): array
    {
        $hostLogKey = $data->meta('host_ref_num') ?? $data->meta('host_log_key');

        if (is_string($hostLogKey) && $hostLogKey !== '') {
            return ['hostLogKey' => $hostLogKey];
        }

        $paymentModel = (string) ($data->meta('payment_model') ?? CreatePaymentData::MODEL_3D_SECURE);

        return ['orderID' => $this->formatReversalOrderId($data->paymentId, $paymentModel)];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function checkCallbackHash(array $payload): bool
    {
        // PosNet'te doğrulama `oosResolveMerchantData` yanıtı üzerinden yapılır.
        return true;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function is3dAuthSuccess(array $payload): bool
    {
        return in_array($this->pick($payload, ['mdStatus']), ['1', '2', '3', '4'], true);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    protected function provision(array $payload): array
    {
        // verify() kendi akışını yürüttüğü için bu yol kullanılmaz.
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
        $approved = $this->pick($provision, ['approved']) === self::SUCCESS_CODE;

        return new VerificationResponse(
            success: $approved,
            paymentId: $this->hostLogKey($provision),
            status: $approved ? 'success' : 'failed',
            raw: ['callback' => $payload, 'provision' => $provision],
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function extractOrderId(array $payload): ?string
    {
        return $this->pick($payload, ['xid', 'orderID', 'orderId']);
    }

    /**
     * securityData = sha256_b64( secretKey;terminalId )
     */
    protected function securityData(): string
    {
        return $this->hash($this->config->secretKey.';'.$this->config->terminalId);
    }

    protected function hash(string $value): string
    {
        return base64_encode(hash('sha256', $value, true));
    }

    /**
     * PosNet tutarları kuruş cinsinden tam sayı olarak bekler.
     */
    protected function formatAmount(Money $money): string
    {
        return $money->toMinorUnitsString();
    }

    /**
     * PosNet taksit alanını iki haneli bekler; tek çekim '00'dır.
     */
    protected function formatInstallment(int $installment): string
    {
        return $installment > 1
            ? str_pad((string) $installment, 2, '0', STR_PAD_LEFT)
            : '00';
    }

    /**
     * Sipariş numarasını 20 haneye sıfırla soldan doldurur.
     *
     * @throws PaymentFailedException Sipariş numarası çok uzunsa
     */
    protected function formatOrderId(string $orderId, int $length = self::ORDER_ID_LENGTH, string $prefix = ''): string
    {
        $padLength = $length - strlen($prefix);

        if (strlen($orderId) > $padLength) {
            throw new PaymentFailedException(
                message: sprintf(
                    "PosNet sipariş numarası en fazla %d karakter olabilir; '%s' %d karakter.",
                    $padLength,
                    $orderId,
                    strlen($orderId),
                ),
                context: ['bank' => $this->config->bank],
            );
        }

        return $prefix.str_pad($orderId, $padLength, '0', STR_PAD_LEFT);
    }

    /**
     * İade/iptal/durum sorgularında sipariş numarası 24 hanedir ve
     * 3D Secure ile alınan siparişler 'TDSC' ön eki taşır.
     */
    protected function formatReversalOrderId(string $orderId, string $paymentModel): string
    {
        $prefix = $paymentModel === CreatePaymentData::MODEL_3D_SECURE ? self::ORDER_ID_3D_PREFIX : '';

        return $this->formatOrderId($orderId, self::ORDER_ID_TOTAL_LENGTH, $prefix);
    }

    /**
     * PosNet üye işyerinin 3D geçidi kimliği (`posnetID`).
     */
    protected function posNetId(): string
    {
        $id = $this->config->extra('posnet_id');

        if (! is_string($id) || $id === '') {
            throw new PaymentFailedException(
                message: "Yapı Kredi PosNet için extra['posnet_id'] zorunludur.",
                context: ['bank' => $this->config->bank],
            );
        }

        return $id;
    }

    /**
     * @param  array<string, mixed>  $response
     */
    protected function hostLogKey(array $response): ?string
    {
        return $this->pick($response, ['hostlogkey', 'hostLogKey']);
    }

    /**
     * PosNet XML'i `xmldata` form alanı içinde ISO-8859-9 olarak bekler.
     *
     * @param  array<string, mixed>  $request
     * @return array<string, mixed>
     */
    protected function postXml(array $request): array
    {
        return $this->client->postXmlAsFormField(
            url: $this->config->endpoint('payment_api'),
            field: 'xmldata',
            data: $request,
            root: 'posnetRequest',
            encoding: 'ISO-8859-9',
        );
    }
}
