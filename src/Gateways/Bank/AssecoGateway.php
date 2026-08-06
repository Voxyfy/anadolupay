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
 * Asseco / Payten (NestPay, eski adıyla EST) Sanal POS Driver'ı
 *
 * Türkiye'de en yaygın sanal POS altyapısıdır. Aynı protokolü kullanan bankalar:
 * Akbank, İş Bankası, Ziraat Bankası, Halkbank, QNB Finansbank, TEB,
 * Şekerbank, Anadolubank ve ING.
 *
 * Protokol özeti:
 *   - 3D formu `hashAlgorithm=ver3` ile imzalanır: alanlar doğal sırada
 *     sıralanır, `hash`/`encoding` alanları çıkarılır, sona secret key
 *     eklenir, değerler `|` ile birleştirilir ve sha512 + base64 alınır.
 *   - Provizyon/iade/iptal istekleri `CC5Request` kök elemanlı XML'dir.
 *   - Başarı kriteri `ProcReturnCode === '00'`.
 */
class AssecoGateway extends AbstractBankGateway
{
    /** Bankanın başarılı işlem için döndürdüğü prosedür kodu. */
    protected const SUCCESS_CODE = '00';

    /** Hash hesabından her zaman çıkarılan alanlar. */
    protected const HASH_EXCLUDED_FIELDS = ['hash', 'encoding', 'nationalidno'];

    /**
     * @return array<string, scalar>
     */
    protected function build3dFormFields(CreatePaymentData $data): array
    {
        $this->config->require(['merchantId', 'secretKey']);

        $inputs = [
            'hashAlgorithm' => 'ver3',
            'clientid' => $this->config->merchantId,
            'storetype' => $this->storeType($data->paymentModel),
            'amount' => $this->formatAmount($data->amount),
            'oid' => $data->orderId,
            'okUrl' => $this->successUrl($data),
            'failUrl' => $this->failUrl($data),
            'rnd' => $this->randomString(),
            'lang' => $data->lang !== '' ? $data->lang : $this->config->lang,
            'currency' => Currency::numeric($data->currency),
            'taksit' => $this->formatInstallment($data->installments()),
            'TranType' => 'Auth',
        ];

        // 3D Host modelinde kart bilgileri banka sayfasında toplanır.
        if ($data->paymentModel !== CreatePaymentData::MODEL_3D_HOST) {
            $card = $this->requireCard($data);

            $inputs['pan'] = $card->number;
            $inputs['Ecom_Payment_Card_ExpDate_Month'] = $card->expireMonth;
            $inputs['Ecom_Payment_Card_ExpDate_Year'] = $card->expireYearShort();
            $inputs['cv2'] = $card->cvv;
        }

        $inputs['hash'] = $this->createHash($inputs);

        return $inputs;
    }

    /**
     * NestPay tek bir 3D geçidi kullanır; model `storetype` alanıyla belirlenir.
     * 3D Host için ayrı bir uç tanımlıysa o kullanılır.
     */
    protected function gateway3dUrl(CreatePaymentData $data): string
    {
        if ($data->paymentModel === CreatePaymentData::MODEL_3D_HOST
            && isset($this->config->endpoints['gateway_3d_host'])) {
            return $this->config->endpoint('gateway_3d_host');
        }

        return $this->config->endpoint('gateway_3d');
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function checkCallbackHash(array $payload): bool
    {
        $incoming = $this->pick($payload, ['HASH']);

        if ($incoming === null) {
            return false;
        }

        return hash_equals($this->createHash($payload), $incoming);
    }

    /**
     * NestPay'de yalnızca `mdStatus === '1'` tam 3D doğrulaması sayılır.
     *
     * @param  array<string, mixed>  $payload
     */
    protected function is3dAuthSuccess(array $payload): bool
    {
        return $this->pick($payload, ['mdStatus']) === '1';
    }

    /**
     * Banka `storetype` alanını dönüşte aynen geri gönderir; ödeme modelini
     * buradan okuyabiliyoruz.
     *
     * @param  array<string, mixed>  $payload
     */
    protected function paymentModelOf(array $payload): string
    {
        return match ($this->pick($payload, ['storetype'])) {
            '3d_pay', '3d_pay_hosting' => CreatePaymentData::MODEL_3D_PAY,
            '3d_host' => CreatePaymentData::MODEL_3D_HOST,
            default => CreatePaymentData::MODEL_3D_SECURE,
        };
    }

    /**
     * 3D doğrulaması sonrası provizyon isteği (CC5Request).
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    protected function provision(array $payload): array
    {
        $request = $this->accountData() + [
            'Type' => 'Auth',
            'IPAddress' => $this->pick($payload, ['clientIp', 'ip'], '') ?? '',
            'OrderId' => $this->extractOrderId($payload) ?? '',
            'Total' => $this->pick($payload, ['amount'], '') ?? '',
            'Currency' => $this->pick($payload, ['currency'], '') ?? '',
            'Taksit' => $this->pick($payload, ['taksit'], '') ?? '',
            'Number' => $this->pick($payload, ['md'], '') ?? '',
            'PayerTxnId' => $this->pick($payload, ['xid'], '') ?? '',
            'PayerSecurityLevel' => $this->pick($payload, ['eci'], '') ?? '',
            'PayerAuthenticationCode' => $this->pick($payload, ['cavv'], '') ?? '',
            'Mode' => 'P',
        ];

        return $this->postXml($request);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function mapCallbackResponse(array $payload): VerificationResponse
    {
        // 3D Pay / 3D Host: provizyon banka tarafında yapıldı, sonuç dönüşte.
        $approved = $this->pick($payload, ['ProcReturnCode']) === self::SUCCESS_CODE;

        return new VerificationResponse(
            success: $approved,
            paymentId: $this->pick($payload, ['TransId']) ?? $this->extractOrderId($payload),
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
        return $this->pick($payload, ['oid', 'OrderId', 'ReturnOid']);
    }

    /**
     * 3D'siz doğrudan provizyon.
     */
    protected function nonSecurePayment(CreatePaymentData $data): PaymentResponse
    {
        $card = $this->requireCard($data);

        $response = $this->postXml($this->accountData() + [
            'Type' => 'Auth',
            'IPAddress' => $data->clientIp(),
            'OrderId' => $data->orderId,
            'Total' => $this->formatAmount($data->amount),
            'Currency' => Currency::numeric($data->currency),
            'Taksit' => $this->formatInstallment($data->installments()),
            'Number' => $card->number,
            'Expires' => $card->expiry('m/y'),
            'Cvv2Val' => $card->cvv,
            'Mode' => 'P',
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
     * Tam veya kısmi iade. Tutar verilmezse banka tam iade uygular.
     */
    public function refund(RefundPaymentData $data): RefundResponse
    {
        $request = $this->accountData() + [
            'Type' => 'Credit',
            'OrderId' => $data->paymentId,
        ];

        if ($data->amount !== null) {
            $request['Total'] = $this->formatAmount($data->amount);
        }

        $response = $this->postXml($request);
        $approved = $this->pick($response, ['ProcReturnCode']) === self::SUCCESS_CODE;

        return new RefundResponse(
            success: $approved,
            refundId: $this->pick($response, ['TransId']),
            errorMessage: $approved ? null : $this->pick($response, ['ErrMsg']),
            raw: $response,
        );
    }

    /**
     * Gün sonu öncesi işlem iptali.
     */
    public function cancel(RefundPaymentData $data): RefundResponse
    {
        $response = $this->postXml($this->accountData() + [
            'Type' => 'Void',
            'OrderId' => $data->paymentId,
        ]);

        $approved = $this->pick($response, ['ProcReturnCode']) === self::SUCCESS_CODE;

        return new RefundResponse(
            success: $approved,
            refundId: $this->pick($response, ['TransId']),
            errorMessage: $approved ? null : $this->pick($response, ['ErrMsg']),
            raw: $response,
        );
    }

    /**
     * Sipariş durumunu sorgular.
     *
     * @return array<string, mixed>
     */
    public function status(string $orderId): array
    {
        return $this->postXml($this->accountData() + [
            'OrderId' => $orderId,
            'Extra' => ['ORDERSTATUS' => 'QUERY'],
        ]);
    }

    /**
     * NestPay `hashAlgorithm=ver3` imzası.
     *
     * Alanlar doğal sırada (büyük/küçük harf duyarsız) sıralanır,
     * `hash`/`encoding`/`nationalidno` çıkarılır, sona secret key eklenir,
     * `|` ve `\` karakterleri kaçırılır ve sha512 + base64 alınır.
     *
     * @param  array<string, mixed>  $fields
     */
    protected function createHash(array $fields): string
    {
        $values = [];

        foreach ($fields as $key => $value) {
            if (! is_scalar($value)) {
                continue;
            }

            if (in_array(strtolower((string) $key), self::HASH_EXCLUDED_FIELDS, true)) {
                continue;
            }

            $values[(string) $key] = (string) $value;
        }

        ksort($values, SORT_NATURAL | SORT_FLAG_CASE);

        $values[] = $this->config->secretKey;

        $escaped = array_map(
            static fn (string $value): string => str_replace(
                ['\\', '|'],
                ['\\\\', '\\|'],
                $value,
            ),
            array_values($values),
        );

        return base64_encode(hash('sha512', implode('|', $escaped), true));
    }

    /**
     * NestPay tek çekimde taksit alanını boş bekler.
     */
    protected function formatInstallment(int $installment): string
    {
        return $installment > 1 ? (string) $installment : '';
    }

    /**
     * NestPay tutarı sabit ondalık yerine PHP'nin doğal float gösterimiyle
     * bekler (örn: 100 => "100", 1.99 => "1.99"). Hash gövdeye birebir aynı
     * dizgiden hesaplandığı için bu formatın korunması kritiktir.
     */
    protected function formatAmount(float $amount): string
    {
        return (string) $amount;
    }

    /**
     * Ödeme modelini bankanın `storetype` değerine çevirir.
     */
    protected function storeType(string $paymentModel): string
    {
        return match ($paymentModel) {
            CreatePaymentData::MODEL_3D_PAY => '3d_pay',
            CreatePaymentData::MODEL_3D_HOST => '3d_host',
            CreatePaymentData::MODEL_NON_SECURE => 'regular',
            default => '3d',
        };
    }

    /**
     * Her XML isteğinde tekrar eden kimlik alanları.
     *
     * @return array<string, string>
     */
    protected function accountData(): array
    {
        $this->config->require(['merchantId', 'username', 'password']);

        return [
            'Name' => $this->config->username,
            'Password' => $this->config->password,
            'ClientId' => $this->config->merchantId,
        ];
    }

    /**
     * NestPay XML API'sine istek gönderir.
     *
     * @param  array<string, mixed>  $request
     * @return array<string, mixed>
     */
    protected function postXml(array $request): array
    {
        return $this->client->postXml(
            url: $this->config->endpoint('payment_api'),
            data: $request,
            root: 'CC5Request',
            encoding: 'ISO-8859-9',
        );
    }
}
