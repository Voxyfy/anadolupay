<?php

declare(strict_types=1);

namespace Voxyfy\AnadoluPay\Exceptions;

/**
 * Banka Beklenmeyen HTTP Yanıtı Döndü
 *
 * Bağlantı kuruldu ancak banka 2xx dışında bir durum kodu ya da
 * çözümlenemeyen bir gövde döndürdü.
 *
 * 5xx yanıtları genellikle bankanın geçici sorunudur; ancak istek bankaya
 * ulaştığı için ödeme işlemlerinde tekrar denemek güvenli değildir.
 * 4xx yanıtları isteğin kendisinde sorun olduğunu gösterir ve tekrar
 * denemek aynı sonucu verir.
 */
class GatewayHttpException extends TransportException
{
    /**
     * @param  array<string, mixed>  $context
     */
    public static function unexpectedStatus(
        string $bank,
        string $url,
        int $status,
        string $body = '',
        array $context = [],
    ): self {
        return new self(
            message: sprintf("'%s' bankası beklenmeyen bir HTTP yanıtı döndü (%d).", $bank, $status),
            // İstek bankaya ulaştı; ödeme işlemlerinde tekrar denemek çift çekim riski taşır.
            safeToRetry: false,
            context: $context + [
                'bank' => $bank,
                'url' => $url,
                'status' => $status,
                'body' => mb_substr($body, 0, 2000),
                'reason' => 'unexpected_status',
            ],
            code: $status,
        );
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public static function unreadableBody(
        string $bank,
        string $url,
        string $body = '',
        array $context = [],
    ): self {
        return new self(
            message: sprintf("'%s' bankasının yanıtı çözümlenemedi.", $bank),
            safeToRetry: false,
            context: $context + [
                'bank' => $bank,
                'url' => $url,
                'body' => mb_substr($body, 0, 2000),
                'reason' => 'unreadable_body',
            ],
        );
    }
}
