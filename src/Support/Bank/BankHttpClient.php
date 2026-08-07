<?php

declare(strict_types=1);

namespace Voxyfy\AnadoluPay\Support\Bank;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Psr\Log\LoggerInterface;
use Voxyfy\AnadoluPay\Exceptions\PaymentFailedException;

/**
 * Banka Sanal POS HTTP İstemcisi
 *
 * Banka uçlarına XML, JSON veya form-encoded istek gönderir ve yanıtı
 * her zaman diziye normalize eder. Bankalar Content-Type başlığını
 * tutarsız kullandığı için yanıt tipi gövdeye bakılarak tespit edilir.
 *
 * Logger verildiğinde her istek ve yanıt, kart verisi maskelenerek
 * kaydedilir. Banka entegrasyonlarında hata ayıklamanın pratikte tek
 * yolu budur: banka yalnızca bir hata kodu döner, sorunun hangi alanda
 * olduğunu ancak gönderilen gövdeyi görerek anlayabilirsiniz.
 */
class BankHttpClient
{
    private readonly SensitiveDataScrubber $scrubber;

    /**
     * @param  int  $timeout  İstek zaman aşımı (saniye)
     * @param  bool  $verifySsl  TLS sertifikası doğrulansın mı (canlıda daima true olmalı)
     * @param  LoggerInterface|null  $logger  Verilirse istek/yanıt loglanır
     * @param  string  $bank  Log kayıtlarında görünen banka anahtarı
     */
    public function __construct(
        private readonly int $timeout = 30,
        private readonly bool $verifySsl = true,
        private readonly ?LoggerInterface $logger = null,
        private readonly string $bank = 'unknown',
        ?SensitiveDataScrubber $scrubber = null,
    ) {
        $this->scrubber = $scrubber ?? new SensitiveDataScrubber;
    }

    /**
     * XML gövdeli POST isteği gönderir.
     *
     * @param  array<array-key, mixed>  $data  XML'e çevrilecek istek verisi
     * @param  string  $root  XML kök elemanının adı
     * @param  string  $encoding  Gövde kodlaması (örn: ISO-8859-9)
     * @param  array<string, string>  $headers
     * @return array<string, mixed>
     */
    public function postXml(
        string $url,
        array $data,
        string $root,
        string $encoding = 'UTF-8',
        array $headers = [],
    ): array {
        $body = Xml::encode($data, $root, $encoding);

        return $this->send($url, $body, $headers + [
            'Content-Type' => 'application/xml; charset='.strtolower($encoding),
        ]);
    }

    /**
     * XML gövdesini `xmldata` gibi tek bir form alanı içinde gönderir.
     *
     * PosNet ve InterPos gibi bazı bankalar XML'i form parametresi olarak bekler.
     *
     * @param  array<array-key, mixed>  $data
     * @param  array<string, string>  $headers
     * @return array<string, mixed>
     */
    public function postXmlAsFormField(
        string $url,
        string $field,
        array $data,
        string $root,
        string $encoding = 'UTF-8',
        array $headers = [],
    ): array {
        $xml = Xml::encode($data, $root, $encoding);

        return $this->postForm($url, [$field => $xml], $headers);
    }

    /**
     * JSON gövdeli POST isteği gönderir.
     *
     * @param  array<array-key, mixed>  $data
     * @param  array<string, string>  $headers
     * @return array<string, mixed>
     */
    public function postJson(string $url, array $data, array $headers = []): array
    {
        $body = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        if ($body === false) {
            throw new PaymentFailedException('İstek JSON olarak kodlanamadı.');
        }

        return $this->send($url, $body, $headers + [
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
        ]);
    }

    /**
     * application/x-www-form-urlencoded POST isteği gönderir.
     *
     * @param  array<string, scalar>  $fields
     * @param  array<string, string>  $headers
     * @return array<string, mixed>
     */
    public function postForm(string $url, array $fields, array $headers = []): array
    {
        $this->logRequest($url, http_build_query($this->scrubber->scrubArray($fields)), scrubbed: true);

        $startedAt = microtime(true);
        $response = $this->request($headers)->asForm()->post($url, $fields);

        $this->logResponse($url, $response, $startedAt);

        return $this->decode($response, $url);
    }

    /**
     * SOAP zarfı gönderir.
     *
     * @param  array<string, string>  $headers
     * @return array<string, mixed>
     */
    public function postSoap(string $url, string $envelope, string $soapAction, array $headers = []): array
    {
        return $this->send($url, $envelope, $headers + [
            'Content-Type' => 'text/xml; charset=utf-8',
            'SOAPAction' => $soapAction,
        ]);
    }

    /**
     * Ham gövdeli POST isteği gönderir ve yanıtı çözümler.
     *
     * @param  array<string, string>  $headers
     * @return array<string, mixed>
     */
    public function send(string $url, string $body, array $headers = []): array
    {
        $this->logRequest($url, $body);

        $startedAt = microtime(true);
        $response = $this->request($headers)->withBody($body, $headers['Content-Type'] ?? 'text/plain')->post($url);

        $this->logResponse($url, $response, $startedAt);

        return $this->decode($response, $url);
    }

    /**
     * XML gövdeli POST isteği gönderir ve yanıtı ham metin olarak döndürür.
     *
     * Bazı bankalar (örn. Kuveyt Türk) 3D adımında çözümlenecek bir veri
     * yapısı değil, doğrudan tarayıcıya basılacak bir HTML sayfası döner.
     *
     * @param  array<array-key, mixed>  $data
     * @param  array<string, string>  $headers
     */
    public function postXmlForRawBody(
        string $url,
        array $data,
        string $root,
        string $encoding = 'UTF-8',
        array $headers = [],
    ): string {
        $body = Xml::encode($data, $root, $encoding);

        $this->logRequest($url, $body);

        $startedAt = microtime(true);
        $response = $this->request($headers + [
            'Content-Type' => 'application/xml; charset='.strtolower($encoding),
        ])->withBody($body, 'application/xml')->post($url);

        // Yanıt bir HTML sayfası; gövdesini loglamak hem gereksiz hem çok
        // hacimli olacağı için yalnızca boyutunu kaydediyoruz.
        $this->logResponse($url, $response, $startedAt, logBody: false);

        return $response->body();
    }

    /**
     * Giden isteği maskeleyerek loglar.
     *
     * @param  bool  $scrubbed  Gövde çağıran tarafından zaten temizlendiyse true
     */
    private function logRequest(string $url, string $body, bool $scrubbed = false): void
    {
        $this->logger?->debug('AnadoluPay banka isteği', [
            'bank' => $this->bank,
            'url' => $url,
            'body' => $scrubbed ? $body : $this->scrubber->scrubBody($body),
        ]);
    }

    /**
     * Gelen yanıtı maskeleyerek loglar.
     */
    private function logResponse(string $url, Response $response, float $startedAt, bool $logBody = true): void
    {
        if ($this->logger === null) {
            return;
        }

        $context = [
            'bank' => $this->bank,
            'url' => $url,
            'status' => $response->status(),
            'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
        ];

        $context[$logBody ? 'body' : 'body_length'] = $logBody
            ? $this->scrubber->scrubBody($response->body())
            : strlen($response->body());

        // Başarısız HTTP yanıtları uyarı seviyesinde: bunlar genellikle
        // yapılandırma veya ağ sorununa işaret eder ve gözden kaçmamalıdır.
        $response->successful()
            ? $this->logger->debug('AnadoluPay banka yanıtı', $context)
            : $this->logger->warning('AnadoluPay banka yanıtı başarısız', $context);
    }

    /**
     * @param  array<string, string>  $headers
     */
    private function request(array $headers): PendingRequest
    {
        return Http::withHeaders($headers)
            ->timeout($this->timeout)
            ->withOptions(['verify' => $this->verifySsl]);
    }

    /**
     * Yanıt gövdesini içeriğine bakarak JSON veya XML olarak çözümler.
     *
     * @return array<string, mixed>
     */
    private function decode(Response $response, string $url): array
    {
        $body = trim($response->body());

        if ($body === '') {
            if (! $response->successful()) {
                throw new PaymentFailedException(
                    message: 'Banka isteği başarısız oldu.',
                    context: ['status' => $response->status(), 'url' => $url],
                );
            }

            return [];
        }

        if (str_starts_with($body, '{') || str_starts_with($body, '[')) {
            $decoded = json_decode($body, true);

            if (is_array($decoded)) {
                return $decoded;
            }
        }

        if (str_starts_with($body, '<')) {
            return Xml::decode($body);
        }

        // Bazı bankalar (örn. PayTR bin sorgusu) düz metin/query string döner.
        parse_str($body, $parsed);

        if ($parsed !== [] && ! isset($parsed[$body])) {
            return $parsed;
        }

        if (! $response->successful()) {
            throw new PaymentFailedException(
                message: 'Banka isteği başarısız oldu.',
                context: ['status' => $response->status(), 'url' => $url, 'body' => mb_substr($body, 0, 2000)],
            );
        }

        return ['raw_body' => $body];
    }
}
