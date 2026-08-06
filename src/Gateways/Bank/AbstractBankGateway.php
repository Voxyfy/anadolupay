<?php

declare(strict_types=1);

namespace Voxyfy\AnadoluPay\Gateways\Bank;

use Voxyfy\AnadoluPay\Contracts\PaymentGatewayInterface;
use Voxyfy\AnadoluPay\DTO\CardData;
use Voxyfy\AnadoluPay\DTO\CreatePaymentData;
use Voxyfy\AnadoluPay\DTO\PaymentResponse;
use Voxyfy\AnadoluPay\DTO\RefundPaymentData;
use Voxyfy\AnadoluPay\DTO\RefundResponse;
use Voxyfy\AnadoluPay\DTO\VerificationResponse;
use Voxyfy\AnadoluPay\DTO\VerifyPaymentData;
use Voxyfy\AnadoluPay\Exceptions\InvalidSignatureException;
use Voxyfy\AnadoluPay\Exceptions\PaymentFailedException;
use Voxyfy\AnadoluPay\Exceptions\UnsupportedOperationException;
use Voxyfy\AnadoluPay\Support\Bank\BankConfig;
use Voxyfy\AnadoluPay\Support\Bank\BankHttpClient;

/**
 * Banka Sanal POS Driver'ları İçin Temel Sınıf
 *
 * Türk bankalarının sanal POS entegrasyonları farklı protokoller kullansa da
 * ortak bir akışı paylaşır:
 *
 *   1. `createPayment()` bankanın 3D geçidine POST edilecek imzalı bir form üretir.
 *   2. Müşteri bankada kimlik doğrulaması yapar, banka `successUrl`'e POST eder.
 *   3. `verify()` dönüş verisinin hash'ini doğrular ve gerekiyorsa provizyon ister.
 *
 * Alt sınıflar bankaya özel istek/yanıt eşlemesini implement eder.
 */
abstract class AbstractBankGateway implements PaymentGatewayInterface
{
    public function __construct(
        protected readonly BankConfig $config,
        protected readonly BankHttpClient $client,
    ) {}

    /**
     * Banka anahtarı ve yapılandırmasından driver üretir.
     *
     * @param  array<string, mixed>  $config
     */
    public static function forBank(string $bank, array $config): static
    {
        $bankConfig = BankConfig::fromArray($bank, $config);

        $client = new BankHttpClient(
            timeout: (int) ($config['timeout'] ?? 30),
            verifySsl: (bool) ($config['verify_ssl'] ?? true),
        );

        // @phpstan-ignore-next-line new.static — alt sınıflar ek kurucu parametresi tanımlamaz.
        return new static($bankConfig, $client);
    }

    /**
     * Bu driver'ın yapılandırmasını döndürür.
     */
    public function config(): BankConfig
    {
        return $this->config;
    }

    /**
     * Ödemeyi başlatır.
     *
     * 3D modellerinde bankanın 3D geçidine POST edilecek form döner;
     * non-secure modelde provizyon doğrudan yapılır.
     */
    public function createPayment(CreatePaymentData $data): PaymentResponse
    {
        if ($data->paymentModel === CreatePaymentData::MODEL_NON_SECURE) {
            return $this->nonSecurePayment($data);
        }

        $fields = $this->build3dFormFields($data);

        return new PaymentResponse(
            success: true,
            paymentId: $data->orderId,
            errorMessage: null,
            raw: ['form_fields' => $fields],
            formAction: $this->gateway3dUrl($data),
            formFields: $fields,
        );
    }

    /**
     * Banka dönüşünü (3D callback) doğrular ve gerekiyorsa provizyonu tamamlar.
     */
    public function verify(VerifyPaymentData $data): VerificationResponse
    {
        $payload = $data->payload;

        if ($this->config->verifyHash && ! $this->checkCallbackHash($payload)) {
            throw new InvalidSignatureException($this->config->bank, [
                'reason' => 'hash_mismatch',
                'order_id' => $this->extractOrderId($payload),
            ]);
        }

        if (! $this->is3dAuthSuccess($payload)) {
            return new VerificationResponse(
                success: false,
                paymentId: $this->extractOrderId($payload),
                status: 'failed',
                raw: ['callback' => $payload],
            );
        }

        if (! $this->requiresProvision($payload)) {
            return $this->mapCallbackResponse($payload);
        }

        $provision = $this->provision($payload);

        return $this->mapProvisionResponse($payload, $provision);
    }

    /**
     * İade işlemi. Alt sınıf desteklemiyorsa istisna fırlatır.
     */
    public function refund(RefundPaymentData $data): RefundResponse
    {
        throw new UnsupportedOperationException('refund', $this->config->bank);
    }

    /**
     * 3D geçidine POST edilecek imzalı form alanlarını üretir.
     *
     * @return array<string, mixed>
     */
    abstract protected function build3dFormFields(CreatePaymentData $data): array;

    /**
     * Formun POST edileceği banka 3D geçidi URL'i.
     */
    protected function gateway3dUrl(CreatePaymentData $data): string
    {
        return $data->paymentModel === CreatePaymentData::MODEL_3D_HOST
            ? $this->config->endpoint('gateway_3d_host')
            : $this->config->endpoint('gateway_3d');
    }

    /**
     * Banka dönüşündeki hash'i doğrular.
     *
     * @param  array<string, mixed>  $payload
     */
    abstract protected function checkCallbackHash(array $payload): bool;

    /**
     * 3D kimlik doğrulamasının başarılı olup olmadığını belirler.
     *
     * @param  array<string, mixed>  $payload
     */
    abstract protected function is3dAuthSuccess(array $payload): bool;

    /**
     * 3D doğrulaması sonrası ayrı bir provizyon isteği gerekiyor mu?
     *
     * 3D Pay ve 3D Host modellerinde banka provizyonu kendisi yapar; sadece
     * klasik 3D Secure modelinde ikinci bir istek gerekir.
     *
     * @param  array<string, mixed>  $payload
     */
    protected function requiresProvision(array $payload): bool
    {
        return $this->paymentModelOf($payload) === CreatePaymentData::MODEL_3D_SECURE;
    }

    /**
     * Banka dönüşünden ödeme modelini çıkarır.
     *
     * @param  array<string, mixed>  $payload
     */
    protected function paymentModelOf(array $payload): string
    {
        $model = $payload['anadolupay_payment_model'] ?? null;

        return is_string($model) && $model !== ''
            ? $model
            : CreatePaymentData::MODEL_3D_SECURE;
    }

    /**
     * 3D doğrulaması sonrası provizyon isteğini gönderir.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    abstract protected function provision(array $payload): array;

    /**
     * Provizyon gerektirmeyen modellerde (3D Pay / 3D Host) dönüşü eşler.
     *
     * @param  array<string, mixed>  $payload
     */
    abstract protected function mapCallbackResponse(array $payload): VerificationResponse;

    /**
     * Provizyon yanıtını normalleştirilmiş doğrulama yanıtına eşler.
     *
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $provision
     */
    abstract protected function mapProvisionResponse(array $payload, array $provision): VerificationResponse;

    /**
     * Banka dönüşünden sipariş numarasını çıkarır.
     *
     * @param  array<string, mixed>  $payload
     */
    abstract protected function extractOrderId(array $payload): ?string;

    /**
     * Non-secure (3D'siz) provizyon. Varsayılan olarak desteklenmez.
     */
    protected function nonSecurePayment(CreatePaymentData $data): PaymentResponse
    {
        throw new UnsupportedOperationException('non_secure_payment', $this->config->bank);
    }

    /**
     * Ödeme için kart bilgisini döndürür; 3D Host dışında zorunludur.
     *
     * @throws PaymentFailedException Kart bilgisi eksikse
     */
    protected function requireCard(CreatePaymentData $data): CardData
    {
        $card = $data->card();

        if (! $card instanceof CardData) {
            throw new PaymentFailedException(
                message: sprintf("'%s' ödeme modeli için kart bilgisi zorunludur.", $data->paymentModel),
                context: ['bank' => $this->config->bank, 'order_id' => $data->orderId],
            );
        }

        return $card;
    }

    /**
     * Başarı URL'ini döndürür.
     *
     * @throws PaymentFailedException URL tanımlı değilse
     */
    protected function successUrl(CreatePaymentData $data): string
    {
        return $this->requireUrl($data->successUrl, 'successUrl');
    }

    /**
     * Hata URL'ini döndürür.
     *
     * @throws PaymentFailedException URL tanımlı değilse
     */
    protected function failUrl(CreatePaymentData $data): string
    {
        return $this->requireUrl($data->failUrl ?? $data->successUrl, 'failUrl');
    }

    /**
     * Bankanın beklediği tutar formatı. Varsayılan: iki ondalıklı nokta ayraçlı.
     */
    protected function formatAmount(float $amount): string
    {
        return number_format($amount, 2, '.', '');
    }

    /**
     * Tutarı kuruş cinsinden tam sayı olarak döndürür (örn: 1.99 => 199).
     */
    protected function amountInMinorUnits(float $amount): string
    {
        return (string) (int) round($amount * 100);
    }

    /**
     * Bankaların hash'e dâhil ettiği rastgele dizgi (rnd) üretir.
     */
    protected function randomString(int $length = 24): string
    {
        return substr(strtoupper(bin2hex(random_bytes((int) ceil($length / 2)))), 0, $length);
    }

    /**
     * İç içe dizide anahtarı büyük/küçük harf duyarsız arar.
     *
     * Bankalar dönüş alan adlarının harf düzenini belgeledikleriyle
     * tutarlı kullanmayabiliyor.
     *
     * @param  array<string, mixed>  $payload
     * @param  list<string>  $keys
     */
    protected function pick(array $payload, array $keys, ?string $default = null): ?string
    {
        $lowered = [];

        foreach ($payload as $key => $value) {
            if (is_scalar($value)) {
                $lowered[strtolower((string) $key)] = (string) $value;
            }
        }

        foreach ($keys as $key) {
            $value = $lowered[strtolower($key)] ?? null;

            if ($value !== null && $value !== '') {
                return $value;
            }
        }

        return $default;
    }

    private function requireUrl(?string $url, string $field): string
    {
        if ($url === null || $url === '') {
            throw new PaymentFailedException(
                message: sprintf("Banka ödemeleri için '%s' zorunludur.", $field),
                context: ['bank' => $this->config->bank],
            );
        }

        return $url;
    }
}
