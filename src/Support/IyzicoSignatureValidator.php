<?php

declare(strict_types=1);

namespace Voxyfy\AnadoluPay\Support;

use Voxyfy\AnadoluPay\Exceptions\InvalidSignatureException;

/**
 * iyzico İmza Doğrulayıcı
 *
 * iyzico iki ayrı imza şeması kullanır ve ikisi de HMAC-SHA256 üretip
 * sonucu **onaltılık** (hex) kodlar:
 *
 *   1. **Yanıt ve callback imzası.** İlgili alanlar iki nokta (`:`) ile
 *      birleştirilir ve secret key HMAC anahtarı olarak kullanılır — veriye
 *      eklenmez. Alan sırası uca göre değişir ve sabittir.
 *
 *   2. **Webhook imzası.** `X-IYZ-SIGNATURE-V3` başlığıyla gelir. Burada
 *      secret key hem HMAC anahtarıdır hem de imzalanan dizginin başına
 *      eklenir; alanlar ayraçsız birleştirilir.
 *
 * Tutar alanlarındaki sondaki sıfırlar imzadan önce atılır: iyzico
 * "10.50" değerini "10.5" olarak imzalar.
 *
 * @see https://docs.iyzico.com/en/advanced/response-signature-validation
 * @see https://docs.iyzico.com/en/advanced/webhook
 */
class IyzicoSignatureValidator
{
    /** 3DS callback'inde imzalanan alanlar, sırasıyla. */
    private const CALLBACK_FIELDS = [
        'conversationData', 'conversationId', 'mdStatus', 'paymentId', 'status',
    ];

    /** `/payment/3dsecure/initialize` yanıtında imzalanan alanlar. */
    private const INITIALIZE_FIELDS = ['paymentId', 'conversationId'];

    /** `/payment/3dsecure/auth` ve `/payment/auth` yanıtlarında imzalanan alanlar. */
    private const AUTH_FIELDS = [
        'paymentId', 'currency', 'basketId', 'conversationId', 'paidPrice', 'price',
    ];

    /** `/payment/refund` yanıtında imzalanan alanlar. */
    private const REFUND_FIELDS = ['paymentId', 'price', 'currency', 'conversationId'];

    /** Tutar biçiminde olan ve sondaki sıfırları atılması gereken alanlar. */
    private const PRICE_FIELDS = ['price', 'paidPrice'];

    public function __construct(
        private readonly string $secretKey,
        private readonly bool $enabled = true,
        private readonly string $webhookSignatureHeader = 'x-iyz-signature-v3',
        private readonly string $signatureParam = 'signature',
    ) {}

    /**
     * 3DS callback imzasını doğrular.
     *
     * @param  array<string, mixed>  $payload
     *
     * @throws InvalidSignatureException
     */
    public function validateCallback(array $payload): void
    {
        $this->assertMatches(
            expected: $this->sign($this->collect($payload, self::CALLBACK_FIELDS)),
            incoming: $this->payloadSignature($payload),
            context: ['scope' => 'callback', 'fields' => self::CALLBACK_FIELDS],
        );
    }

    /**
     * `/payment/3dsecure/initialize` yanıt imzasını doğrular.
     *
     * @param  array<string, mixed>  $response
     *
     * @throws InvalidSignatureException
     */
    public function validateInitializeResponse(array $response): void
    {
        $this->assertMatches(
            expected: $this->sign($this->collect($response, self::INITIALIZE_FIELDS)),
            incoming: $this->payloadSignature($response),
            context: ['scope' => 'initialize', 'fields' => self::INITIALIZE_FIELDS],
        );
    }

    /**
     * `/payment/3dsecure/auth` yanıt imzasını doğrular.
     *
     * @param  array<string, mixed>  $response
     *
     * @throws InvalidSignatureException
     */
    public function validateAuthResponse(array $response): void
    {
        $this->assertMatches(
            expected: $this->sign($this->collect($response, self::AUTH_FIELDS)),
            incoming: $this->payloadSignature($response),
            context: ['scope' => 'auth', 'fields' => self::AUTH_FIELDS],
        );
    }

    /**
     * `/payment/refund` yanıt imzasını doğrular.
     *
     * @param  array<string, mixed>  $response
     *
     * @throws InvalidSignatureException
     */
    public function validateRefundResponse(array $response): void
    {
        $this->assertMatches(
            expected: $this->sign($this->collect($response, self::REFUND_FIELDS)),
            incoming: $this->payloadSignature($response),
            context: ['scope' => 'refund', 'fields' => self::REFUND_FIELDS],
        );
    }

    /**
     * Webhook bildirimini `X-IYZ-SIGNATURE-V3` başlığıyla doğrular.
     *
     * iyzico bildirim biçimine göre iki farklı alan dizisi kullanır:
     * ödeme bildirimlerinde `paymentId`, ortak ödeme sayfası (HPP)
     * bildirimlerinde ayrıca `token` yer alır.
     *
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $headers
     *
     * @throws InvalidSignatureException
     */
    public function validateWebhook(array $payload, array $headers): void
    {
        if (! $this->enabled) {
            return;
        }

        $incoming = $this->headerValue($headers, $this->webhookSignatureHeader);

        if ($incoming === null || $incoming === '') {
            throw new InvalidSignatureException('iyzico', [
                'reason' => 'missing_signature',
                'header' => $this->webhookSignatureHeader,
            ]);
        }

        $eventType = $this->value($payload, 'iyziEventType');
        $status = $this->value($payload, 'status');
        $conversationId = $this->value($payload, 'paymentConversationId');
        $token = $this->value($payload, 'token');

        // HPP bildirimlerinde token alanı bulunur ve imzaya dâhildir.
        $message = $token !== ''
            ? $this->secretKey.$eventType.$this->value($payload, 'iyziPaymentId').$token.$conversationId.$status
            : $this->secretKey.$eventType.$this->value($payload, 'paymentId').$conversationId.$status;

        $this->assertMatches(
            expected: hash_hmac('sha256', $message, $this->secretKey),
            incoming: $incoming,
            context: ['scope' => 'webhook', 'event_type' => $eventType],
        );
    }

    /**
     * Alan değerlerini iki nokta ile birleştirip HMAC-SHA256 (hex) üretir.
     *
     * @param  list<string>  $values
     */
    public function sign(array $values): string
    {
        return hash_hmac('sha256', implode(':', $values), $this->secretKey);
    }

    /**
     * Bir tutarı iyzico'nun imzada kullandığı biçime getirir.
     *
     * Sondaki sıfırlar atılır ancak en az bir ondalık hane korunur:
     * "10.50" => "10.5", "10.00" => "10.0", "10.5" => "10.5".
     */
    public function normalizePrice(string $price): string
    {
        if (! str_contains($price, '.')) {
            return $price;
        }

        $trimmed = rtrim($price, '0');

        return str_ends_with($trimmed, '.') ? $trimmed.'0' : $trimmed;
    }

    /**
     * İmzalanacak alan değerlerini sırayla toplar.
     *
     * @param  array<string, mixed>  $payload
     * @param  list<string>  $fields
     * @return list<string>
     */
    private function collect(array $payload, array $fields): array
    {
        $values = [];

        foreach ($fields as $field) {
            $value = $this->value($payload, $field);

            $values[] = in_array($field, self::PRICE_FIELDS, true)
                ? $this->normalizePrice($value)
                : $value;
        }

        return $values;
    }

    /**
     * @param  array<string, mixed>  $context
     *
     * @throws InvalidSignatureException
     */
    private function assertMatches(string $expected, ?string $incoming, array $context): void
    {
        if (! $this->enabled) {
            return;
        }

        if ($incoming === null || $incoming === '') {
            throw new InvalidSignatureException('iyzico', $context + ['reason' => 'missing_signature']);
        }

        if (! hash_equals($expected, $incoming)) {
            throw new InvalidSignatureException('iyzico', $context + [
                'reason' => 'signature_mismatch',
                'expected' => $expected,
                'incoming' => $incoming,
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function payloadSignature(array $payload): ?string
    {
        $signature = $payload[$this->signatureParam] ?? null;

        return is_string($signature) ? $signature : null;
    }

    /**
     * Alanı dizgi olarak okur; yoksa boş dizgi döndürür.
     *
     * iyzico imzada eksik alanları boş dizgi olarak değerlendirir.
     *
     * @param  array<string, mixed>  $payload
     */
    private function value(array $payload, string $key): string
    {
        $value = $payload[$key] ?? null;

        return is_scalar($value) ? (string) $value : '';
    }

    /**
     * Laravel'in başlık dizisi biçimini destekler.
     *
     * @param  array<string, mixed>  $headers
     */
    private function headerValue(array $headers, string $name): ?string
    {
        $name = strtolower($name);

        foreach ($headers as $key => $value) {
            if (strtolower((string) $key) !== $name) {
                continue;
            }

            if (is_array($value)) {
                $first = $value[0] ?? null;

                return is_string($first) ? $first : null;
            }

            return is_string($value) ? $value : null;
        }

        return null;
    }
}
