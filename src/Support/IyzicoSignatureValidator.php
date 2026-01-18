<?php

declare(strict_types=1);

namespace Voxyfy\AnadoluPay\Support;

use Voxyfy\AnadoluPay\Exceptions\InvalidSignatureException;

/**
 * Iyzico imza dogrulama yardimcisi.
 *
 * Not: Iyzico resmi imza semasi farkli olabilir; gerekli alanlar ve format
 * dogrulanip bu sinif guncellenmelidir.
 */
class IyzicoSignatureValidator
{
    public function __construct(
        private readonly string $secretKey,
        private readonly bool $enabled,
        private readonly string $signatureHeader,
        private readonly string $signatureParam,
    ) {}

    /**
     * Redirect callback imzasini dogrular.
     *
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $headers
     */
    public function validateCallback(array $payload, array $headers): void
    {
        if (! $this->enabled) {
            return;
        }

        $incomingSignature = $this->extractSignature($payload, $headers);

        if ($incomingSignature === null || $incomingSignature === '') {
            throw new InvalidSignatureException('iyzico', [
                'reason' => 'missing_signature',
                'header' => $this->signatureHeader,
                'param' => $this->signatureParam,
            ]);
        }

        $message = $this->callbackMessage($payload);
        $expected = $this->hmacBase64($message);

        if (! hash_equals($expected, $incomingSignature)) {
            throw new InvalidSignatureException('iyzico', [
                'reason' => 'signature_mismatch',
                'expected' => $expected,
                'incoming' => $incomingSignature,
                'message' => $message,
            ]);
        }
    }

    /**
     * Webhook imzasini dogrular.
     *
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $headers
     */
    public function validateWebhook(array $payload, array $headers): void
    {
        if (! $this->enabled) {
            return;
        }

        $incomingSignature = $this->extractSignature($payload, $headers);

        if ($incomingSignature === null || $incomingSignature === '') {
            throw new InvalidSignatureException('iyzico', [
                'reason' => 'missing_signature',
                'header' => $this->signatureHeader,
                'param' => $this->signatureParam,
            ]);
        }

        $message = $this->webhookMessage($payload);
        $expected = $this->hmacBase64($message);

        if (! hash_equals($expected, $incomingSignature)) {
            throw new InvalidSignatureException('iyzico', [
                'reason' => 'signature_mismatch',
                'expected' => $expected,
                'incoming' => $incomingSignature,
                'message' => $message,
            ]);
        }
    }

    /**
     * TODO: Iyzico resmi imza algoritmasini ve alan listesini dogrula.
     */
    private function callbackMessage(array $payload): string
    {
        $parts = [
            (string) ($payload['conversationId'] ?? ''),
            (string) ($payload['paymentId'] ?? ''),
            (string) ($payload['status'] ?? ''),
            (string) ($payload['mdStatus'] ?? ''),
        ];

        return implode('|', $parts);
    }

    /**
     * TODO: Iyzico resmi imza algoritmasini ve alan listesini dogrula.
     */
    private function webhookMessage(array $payload): string
    {
        $parts = [
            (string) ($payload['paymentId'] ?? ''),
            (string) ($payload['paymentConversationId'] ?? ''),
            (string) ($payload['status'] ?? ''),
            (string) ($payload['iyziEventType'] ?? ''),
            (string) ($payload['iyziEventTime'] ?? ''),
        ];

        return implode('|', $parts);
    }

    private function hmacBase64(string $message): string
    {
        return base64_encode(hash_hmac('sha256', $message, $this->secretKey, true));
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $headers
     */
    private function extractSignature(array $payload, array $headers): ?string
    {
        $headerValue = $this->headerValue($headers, $this->signatureHeader);

        if ($headerValue !== null && $headerValue !== '') {
            return $headerValue;
        }

        $paramValue = $payload[$this->signatureParam] ?? null;

        return is_string($paramValue) ? $paramValue : null;
    }

    /**
     * Laravel headers formatini destekler.
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
