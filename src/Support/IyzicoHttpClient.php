<?php

declare(strict_types=1);

namespace Voxyfy\AnadoluPay\Support;

use Illuminate\Support\Facades\Http;
use Voxyfy\AnadoluPay\Exceptions\PaymentFailedException;

/**
 * Iyzico API istekleri için basit HTTP istemcisi.
 */
class IyzicoHttpClient
{
    public function __construct(
        private readonly string $baseUrl,
        private readonly string $apiKey,
        private readonly string $secretKey,
    ) {}

    /**
     * Iyzico'ya JSON POST isteği gönderir.
     *
     * @param  string  $path  API yolu (örn: /payment/3dsecure/initialize)
     * @param  array<string, mixed>  $payload  İstek gövdesi
     * @return array<string, mixed>
     */
    public function post(string $path, array $payload): array
    {
        $random = bin2hex(random_bytes(8));
        $body = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $body = $body === false ? '' : $body;
        // TODO: Iyzico resmi imza semasini dogrula ve gerekirse guncelle.
        $signature = base64_encode(hash_hmac('sha256', $this->apiKey.$random.$body, $this->secretKey, true));
        $authorization = 'IYZWSv2 '.$this->apiKey.':'.$signature;

        $response = Http::baseUrl($this->baseUrl)
            ->asJson()
            ->withHeaders([
                'x-iyzi-rnd' => $random,
                'Authorization' => $authorization,
            ])
            ->post($path, $payload);

        if (! $response->successful()) {
            throw new PaymentFailedException(
                message: 'Iyzico API isteği başarısız oldu.',
                context: [
                    'status' => $response->status(),
                    'body' => $response->body(),
                    'path' => $path,
                ],
            );
        }

        $decoded = $response->json();

        if (! is_array($decoded)) {
            throw new PaymentFailedException(
                message: 'Iyzico API geçersiz JSON döndü.',
                context: [
                    'status' => $response->status(),
                    'body' => $response->body(),
                    'path' => $path,
                ],
            );
        }

        return $decoded;
    }
}
