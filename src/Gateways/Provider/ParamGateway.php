<?php

declare(strict_types=1);

namespace Voxyfy\AnadoluPay\Gateways\Provider;

use Voxyfy\AnadoluPay\DTO\CreatePaymentData;
use Voxyfy\AnadoluPay\DTO\PaymentResponse;
use Voxyfy\AnadoluPay\DTO\RefundPaymentData;
use Voxyfy\AnadoluPay\DTO\RefundResponse;
use Voxyfy\AnadoluPay\DTO\VerificationResponse;
use Voxyfy\AnadoluPay\Exceptions\PaymentFailedException;
use Voxyfy\AnadoluPay\Gateways\Bank\AbstractBankGateway;
use Voxyfy\AnadoluPay\Support\Bank\Xml;
use Voxyfy\AnadoluPay\Support\Money;

/**
 * Param (TURK Elektronik Para A.Ş.) Driver'ı
 *
 * Param, diğer sağlayıcıların aksine SOAP tabanlıdır.
 *
 * Protokol özeti:
 *   - Kimlik bilgileri her istekte `G` bloğunda taşınır
 *     (CLIENT_CODE / CLIENT_USERNAME / CLIENT_PASSWORD) ve ayrıca `GUID`.
 *   - Tutarlar virgüllü ondalıkla gönderilir ("19,90"); yalnızca iade/iptal
 *     işlemlerinde nokta kullanılır.
 *   - İstek imzası (`Islem_Hash`): CLIENT_CODE + GUID + Taksit + Islem_Tutar +
 *     Toplam_Tutar + Siparis_ID, ISO-8859-9'a çevrilip sha1 + base64.
 *   - 3D Secure adımında Param hazır bir HTML (`UCD_HTML`) döner.
 *   - Dönüş imzası: islemGUID + md + mdStatus + orderId + GUID → sha1 + base64.
 */
class ParamGateway extends AbstractBankGateway
{
    /** SOAP servisinin ad alanı. */
    protected const NAMESPACE = 'https://turkpos.com.tr/';

    /**
     * Param 3D Secure adımında hazır HTML döner.
     */
    public function createPayment(CreatePaymentData $data): PaymentResponse
    {
        if ($data->paymentModel === CreatePaymentData::MODEL_NON_SECURE) {
            return $this->nonSecurePayment($data);
        }

        $operation = $data->paymentModel === CreatePaymentData::MODEL_3D_PAY
            ? 'Pos_Odeme'
            : 'TP_WMD_UCD';

        $response = $this->call($operation, $this->paymentRequest($data, $operation));
        $result = $this->result($response, $operation);

        // 3D Pay: müşteri Param'ın döndüğü URL'e yönlendirilir.
        if ($operation === 'Pos_Odeme') {
            $url = (string) ($result['UCD_URL'] ?? '');

            if ($url === '') {
                throw new PaymentFailedException(
                    message: (string) ($result['Sonuc_Str'] ?? 'Param 3D Pay başlatma başarısız.'),
                    context: ['response' => $response],
                );
            }

            return new PaymentResponse(
                success: true,
                paymentId: $data->orderId,
                redirectUrl: $url,
                raw: $result,
            );
        }

        $html = (string) ($result['UCD_HTML'] ?? '');

        if ($html === '') {
            throw new PaymentFailedException(
                message: (string) ($result['Sonuc_Str'] ?? 'Param 3D Secure başlatma başarısız.'),
                context: ['response' => $response],
            );
        }

        return new PaymentResponse(
            success: true,
            paymentId: $data->orderId,
            raw: $result,
            htmlContent: $html,
        );
    }

    /**
     * @return array<string, mixed>
     */
    protected function build3dFormFields(CreatePaymentData $data): array
    {
        // createPayment() hazır HTML döndüğü için bu yol kullanılmaz.
        return $this->paymentRequest($data, 'TP_WMD_UCD');
    }

    /**
     * Ödeme isteği gövdesi.
     *
     * @return array<string, mixed>
     */
    protected function paymentRequest(CreatePaymentData $data, string $operation): array
    {
        $this->config->require(['merchantId', 'username', 'password', 'secretKey']);

        $card = $this->requireCard($data);
        $amount = $this->formatAmount($data->money());

        $request = $this->accountData() + [
            '@xmlns' => self::NAMESPACE,
            'Islem_Guvenlik_Tip' => '3D',
            'Islem_ID' => $this->randomString(),
            'IPAdr' => $data->clientIp(),
            'Siparis_ID' => $data->orderId,
            'Islem_Tutar' => $amount,
            'Toplam_Tutar' => $amount,
            'Basarili_URL' => $this->successUrl($data),
            'Hata_URL' => $this->failUrl($data),
            'Taksit' => $this->formatInstallment($data->installments()),
            'KK_Sahibi' => $card->holderName ?? '',
            'KK_No' => $card->number,
            'KK_SK_Ay' => $card->expireMonth,
            'KK_SK_Yil' => $card->expireYearLong(),
            'KK_CVC' => $card->cvv,
            // Opsiyonel olmasına rağmen hiç gönderilmediğinde servis hata veriyor.
            'KK_Sahibi_GSM' => (string) ($data->customer['phone'] ?? ''),
        ];

        if (strtoupper($data->currency) !== 'TRY') {
            $request['Doviz_Kodu'] = strtoupper($data->currency);
        }

        $request['Islem_Hash'] = $this->createHash($request, $operation);

        return $request;
    }

    /**
     * Dönüş imzası: islemGUID + md + mdStatus + orderId + GUID.
     *
     * @param  array<string, mixed>  $payload
     */
    protected function checkCallbackHash(array $payload): bool
    {
        $incoming = $this->pick($payload, ['islemHash']);

        if ($incoming === null) {
            return false;
        }

        $expected = $this->hash(implode('', [
            $this->pick($payload, ['islemGUID'], '') ?? '',
            $this->pick($payload, ['md'], '') ?? '',
            $this->pick($payload, ['mdStatus'], '') ?? '',
            $this->pick($payload, ['orderId'], '') ?? '',
            $this->config->secretKey,
        ]));

        return hash_equals($expected, $incoming);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function is3dAuthSuccess(array $payload): bool
    {
        return $this->pick($payload, ['mdStatus']) === '1';
    }

    /**
     * 3D doğrulaması sonrası `TP_WMD_Pay` ile provizyon tamamlanır.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    protected function provision(array $payload): array
    {
        return $this->call('TP_WMD_Pay', $this->accountData() + [
            '@xmlns' => self::NAMESPACE,
            'UCD_MD' => $this->pick($payload, ['md'], '') ?? '',
            'Islem_GUID' => $this->pick($payload, ['islemGUID'], '') ?? '',
            'Siparis_ID' => $this->extractOrderId($payload) ?? '',
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function mapCallbackResponse(array $payload): VerificationResponse
    {
        $approved = $this->is3dAuthSuccess($payload);

        return new VerificationResponse(
            success: $approved,
            paymentId: $this->pick($payload, ['islemGUID']) ?? $this->extractOrderId($payload),
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
        $result = $this->result($provision, 'TP_WMD_Pay');
        $approved = (string) ($result['Sonuc'] ?? '') === '1';

        return new VerificationResponse(
            success: $approved,
            paymentId: (string) ($result['Dekont_ID'] ?? '') !== ''
                ? (string) $result['Dekont_ID']
                : $this->extractOrderId($payload),
            status: $approved ? 'success' : 'failed',
            raw: ['callback' => $payload, 'provision' => $provision],
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function extractOrderId(array $payload): ?string
    {
        return $this->pick($payload, ['orderId', 'Siparis_ID']);
    }

    /**
     * 3D'siz doğrudan ödeme.
     */
    protected function nonSecurePayment(CreatePaymentData $data): PaymentResponse
    {
        $request = $this->paymentRequest($data, 'TP_WMD_UCD');
        $request['Islem_Guvenlik_Tip'] = 'NS';
        $request['Islem_Hash'] = $this->createHash($request, 'TP_WMD_UCD');

        $response = $this->call('TP_WMD_UCD', $request);
        $result = $this->result($response, 'TP_WMD_UCD');
        $approved = (string) ($result['Sonuc'] ?? '') === '1';

        return new PaymentResponse(
            success: $approved,
            paymentId: (string) ($result['Dekont_ID'] ?? $data->orderId),
            errorMessage: $approved ? null : (string) ($result['Sonuc_Str'] ?? ''),
            raw: $result,
            errorCode: $approved ? null : (string) ($result['Sonuc'] ?? ''),
        );
    }

    /**
     * Tam veya kısmi iade. Param iptal ve iadeyi aynı serviste yürütür.
     */
    public function refund(RefundPaymentData $data): RefundResponse
    {
        $request = $this->accountData() + [
            '@xmlns' => self::NAMESPACE,
            'Durum' => $data->money() !== null ? 'IADE' : 'IPTAL',
            'Siparis_ID' => $data->paymentId,
            'Tutar' => ($data->money() ?? Money::fromMinorUnits(0, $data->currency))->toDecimalString(),
        ];

        $response = $this->call('TP_Islem_Iptal_Iade_Kismi2', $request);
        $result = $this->result($response, 'TP_Islem_Iptal_Iade_Kismi2');
        $approved = (string) ($result['Sonuc'] ?? '') === '1';

        return new RefundResponse(
            success: $approved,
            refundId: isset($result['Dekont_ID']) ? (string) $result['Dekont_ID'] : null,
            errorMessage: $approved ? null : (string) ($result['Sonuc_Str'] ?? ''),
            raw: $result,
        );
    }

    /**
     * İstek imzası.
     *
     * 3D Pay (`Pos_Odeme`) ve TRY dışı işlemler farklı alan sırası kullanır.
     *
     * @param  array<string, mixed>  $request
     */
    protected function createHash(array $request, string $operation): string
    {
        $clientCode = (string) ($request['G']['CLIENT_CODE'] ?? '');
        $guid = (string) ($request['GUID'] ?? '');

        if (isset($request['Doviz_Kodu'])) {
            // Döviz ödemelerinde taksit alanı hash'e girmez.
            $parts = [
                $clientCode,
                $guid,
                (string) $request['Islem_Tutar'],
                (string) $request['Toplam_Tutar'],
                (string) $request['Siparis_ID'],
                (string) $request['Hata_URL'],
                (string) $request['Basarili_URL'],
            ];
        } elseif ($operation === 'Pos_Odeme') {
            $parts = [
                $clientCode,
                $guid,
                (string) $request['Taksit'],
                (string) $request['Islem_Tutar'],
                (string) $request['Toplam_Tutar'],
                (string) $request['Siparis_ID'],
                (string) $request['Hata_URL'],
                (string) $request['Basarili_URL'],
            ];
        } else {
            $parts = [
                $clientCode,
                $guid,
                (string) $request['Taksit'],
                (string) $request['Islem_Tutar'],
                (string) $request['Toplam_Tutar'],
                (string) $request['Siparis_ID'],
            ];
        }

        $value = implode('', $parts);
        $converted = @iconv('UTF-8', 'ISO-8859-9//TRANSLIT', $value);

        return $this->hash($converted === false ? $value : $converted);
    }

    protected function hash(string $value): string
    {
        return base64_encode(hash('sha1', $value, true));
    }

    /**
     * Param tutarları virgüllü ondalıkla bekler ("19,90").
     */
    protected function formatAmount(Money $money): string
    {
        return str_replace('.', ',', $money->toDecimalString());
    }

    /**
     * Param tek çekimi '1' olarak bekler.
     */
    protected function formatInstallment(int $installment): string
    {
        return $installment > 1 ? (string) $installment : '1';
    }

    /**
     * @return array<string, mixed>
     */
    protected function accountData(): array
    {
        return [
            'G' => [
                'CLIENT_CODE' => $this->config->merchantId,
                'CLIENT_USERNAME' => $this->config->username,
                'CLIENT_PASSWORD' => $this->config->password,
            ],
            'GUID' => $this->config->secretKey,
        ];
    }

    /**
     * SOAP yanıtından `<Operation>Response`/`<Operation>Result` bloğunu çıkarır.
     *
     * @param  array<string, mixed>  $response
     * @return array<string, mixed>
     */
    protected function result(array $response, string $operation): array
    {
        $body = $response['Body'] ?? $response['soap:Body'] ?? $response;

        if (! is_array($body)) {
            return [];
        }

        $wrapper = $body[$operation.'Response'] ?? null;

        if (! is_array($wrapper)) {
            return $body;
        }

        $result = $wrapper[$operation.'Result'] ?? null;

        return is_array($result) ? $result : $wrapper;
    }

    /**
     * SOAP zarfını oluşturup gönderir.
     *
     * @param  array<string, mixed>  $request
     * @return array<string, mixed>
     */
    protected function call(string $operation, array $request): array
    {
        $envelope = '<?xml version="1.0" encoding="utf-8"?>'
            .'<soap:Envelope xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"'
            .' xmlns:xsd="http://www.w3.org/2001/XMLSchema"'
            .' xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/"><soap:Body>'
            .Xml::encode($request, $operation, withDeclaration: false)
            .'</soap:Body></soap:Envelope>';

        return $this->client->postSoap(
            url: $this->config->endpoint('payment_api'),
            envelope: $envelope,
            soapAction: self::NAMESPACE.$operation,
        );
    }
}
