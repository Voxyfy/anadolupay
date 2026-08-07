<?php

declare(strict_types=1);

namespace Voxyfy\AnadoluPay\Support;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Psr\Log\LoggerInterface;
use Voxyfy\AnadoluPay\Exceptions\GatewayHttpException;
use Voxyfy\AnadoluPay\Exceptions\GatewayUnreachableException;
use Voxyfy\AnadoluPay\Exceptions\PaymentFailedException;
use Voxyfy\AnadoluPay\Support\Bank\SensitiveDataScrubber;

/**
 * iyzico API İstemcisi (IYZWSv2 kimlik doğrulama)
 *
 * İmza şeması:
 *
 *     signature = hex( hmac_sha256( randomKey + uriPath + body, secretKey ) )
 *     authString = "apiKey:{apiKey}&randomKey:{randomKey}&signature:{signature}"
 *     Authorization: "IYZWSv2 " + base64( authString )
 *     x-iyzi-rnd: randomKey
 *
 * İmza gövdenin **tam olarak gönderilen hâli** üzerinden hesaplanır; bu
 * yüzden gövde bir kez kodlanır ve hem imzada hem istekte aynı dizgi
 * kullanılır. Gövdeyi HTTP istemcisine yeniden kodlatmak (örneğin
 * `->post($url, $array)` çağırmak) imzayı sessizce bozar.
 *
 * @see https://docs.iyzico.com/en/getting-started/preliminaries/authentication/hmacsha256-auth
 */
class IyzicoHttpClient
{
    private readonly SensitiveDataScrubber $scrubber;

    public function __construct(
        private readonly string $baseUrl,
        private readonly string $apiKey,
        private readonly string $secretKey,
        private readonly int $timeout = 30,
        private readonly ?LoggerInterface $logger = null,
        ?SensitiveDataScrubber $scrubber = null,
    ) {
        $this->scrubber = $scrubber ?? new SensitiveDataScrubber;
    }

    /**
     * iyzico'ya imzalı JSON POST isteği gönderir.
     *
     * @param  string  $path  API yolu (örn: /payment/3dsecure/initialize)
     * @param  array<string, mixed>  $payload  İstek gövdesi
     * @return array<string, mixed>
     */
    public function post(string $path, array $payload): array
    {
        $body = $this->encode($payload);
        $randomKey = $this->generateRandomKey();

        $this->logger?->debug('AnadoluPay iyzico isteği', [
            'path' => $path,
            'body' => $this->scrubber->scrubBody($body),
        ]);

        $startedAt = microtime(true);

        try {
            $response = Http::baseUrl($this->baseUrl)
                ->timeout($this->timeout)
                ->withHeaders([
                    'Authorization' => $this->authorizationHeader($path, $body, $randomKey),
                    'x-iyzi-rnd' => $randomKey,
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json',
                ])
                ->withBody($body, 'application/json')
                ->post($path);
        } catch (ConnectionException $exception) {
            throw str_contains(strtolower($exception->getMessage()), 'time')
                ? GatewayUnreachableException::timedOut('iyzico', $path, $this->timeout, previous: $exception)
                : GatewayUnreachableException::connectionFailed('iyzico', $path, previous: $exception);
        }

        $this->logger?->debug('AnadoluPay iyzico yanıtı', [
            'path' => $path,
            'status' => $response->status(),
            'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
            'body' => $this->scrubber->scrubBody($response->body()),
        ]);

        if (! $response->successful()) {
            throw GatewayHttpException::unexpectedStatus(
                'iyzico',
                $path,
                $response->status(),
                $this->scrubber->scrubBody($response->body()),
            );
        }

        $decoded = $response->json();

        if (! is_array($decoded)) {
            throw GatewayHttpException::unreadableBody(
                'iyzico',
                $path,
                $this->scrubber->scrubBody($response->body()),
            );
        }

        return $decoded;
    }

    /**
     * IYZWSv2 Authorization başlığını üretir.
     */
    public function authorizationHeader(string $path, string $body, string $randomKey): string
    {
        $signature = hash_hmac('sha256', $randomKey.$this->uriPath($path).$body, $this->secretKey);

        $authString = sprintf(
            'apiKey:%s&randomKey:%s&signature:%s',
            $this->apiKey,
            $randomKey,
            $signature,
        );

        return 'IYZWSv2 '.base64_encode($authString);
    }

    /**
     * İmzaya giren yol; query string imzaya dâhil edilmez.
     */
    private function uriPath(string $path): string
    {
        $queryPosition = strpos($path, '?');

        return $queryPosition === false ? $path : substr($path, 0, $queryPosition);
    }

    /**
     * Gövdeyi imzada ve istekte kullanılacak tek bir dizgiye kodlar.
     *
     * @param  array<string, mixed>  $payload
     */
    private function encode(array $payload): string
    {
        $body = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        if ($body === false) {
            throw new PaymentFailedException('iyzico isteği JSON olarak kodlanamadı.');
        }

        return $body;
    }

    /**
     * iyzico her istekte tekrar etmeyen bir rastgele anahtar bekler.
     */
    private function generateRandomKey(): string
    {
        return (string) (int) (microtime(true) * 1000).bin2hex(random_bytes(4));
    }
}
