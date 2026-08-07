<?php

declare(strict_types=1);

namespace Voxyfy\AnadoluPay\Gateways\Bank;

use Illuminate\Support\Facades\Event;
use Psr\Log\LoggerInterface;
use Throwable;
use Voxyfy\AnadoluPay\Contracts\PaymentGatewayInterface;
use Voxyfy\AnadoluPay\DTO\CardData;
use Voxyfy\AnadoluPay\DTO\CreatePaymentData;
use Voxyfy\AnadoluPay\DTO\PaymentResponse;
use Voxyfy\AnadoluPay\DTO\RecurringPlan;
use Voxyfy\AnadoluPay\DTO\RefundPaymentData;
use Voxyfy\AnadoluPay\DTO\RefundResponse;
use Voxyfy\AnadoluPay\DTO\VerificationResponse;
use Voxyfy\AnadoluPay\DTO\VerifyPaymentData;
use Voxyfy\AnadoluPay\Events\PaymentFailed;
use Voxyfy\AnadoluPay\Events\PaymentInitiated;
use Voxyfy\AnadoluPay\Events\PaymentVerified;
use Voxyfy\AnadoluPay\Events\RefundIssued;
use Voxyfy\AnadoluPay\Exceptions\InvalidSignatureException;
use Voxyfy\AnadoluPay\Exceptions\PaymentFailedException;
use Voxyfy\AnadoluPay\Exceptions\UnsupportedOperationException;
use Voxyfy\AnadoluPay\Support\Bank\BankConfig;
use Voxyfy\AnadoluPay\Support\Bank\BankHttpClient;
use Voxyfy\AnadoluPay\Support\IdempotencyGuard;
use Voxyfy\AnadoluPay\Support\LoggerResolver;
use Voxyfy\AnadoluPay\Support\Money;

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
        protected readonly ?IdempotencyGuard $idempotency = null,
    ) {}

    /**
     * Banka anahtarı ve yapılandırmasından driver üretir.
     *
     * @param  array<string, mixed>  $config
     */
    public static function forBank(string $bank, array $config): static
    {
        $bankConfig = BankConfig::fromArray($bank, $config);

        $retry = config('anadolupay.retry', []);

        $client = new BankHttpClient(
            timeout: (int) ($config['timeout'] ?? 30),
            verifySsl: (bool) ($config['verify_ssl'] ?? true),
            logger: static::resolveLogger(),
            bank: $bank,
            retryTimes: (int) ($config['retry_times'] ?? $retry['times'] ?? 0),
            retrySleepMs: (int) ($config['retry_sleep_ms'] ?? $retry['sleep_ms'] ?? 250),
        );

        // @phpstan-ignore-next-line new.static — alt sınıflar ek kurucu parametresi tanımlamaz.
        return new static($bankConfig, $client, IdempotencyGuard::fromConfig());
    }

    /**
     * Yapılandırmaya göre log kanalını çözümler.
     */
    protected static function resolveLogger(): ?LoggerInterface
    {
        return LoggerResolver::resolve();
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
     * Mükerrer ödeme koruması, event yayını ve hata sarmalama bu metotta
     * tek yerde yapılır; bankaya özel davranış `performCreatePayment()`
     * içinde yaşar. Alt sınıflar bu yüzden `createPayment()` yerine
     * `performCreatePayment()` metodunu override eder.
     */
    final public function createPayment(CreatePaymentData $data): PaymentResponse
    {
        $this->idempotency?->acquire($this->config->bank, $data->orderId);

        try {
            $response = $this->performCreatePayment($data);
        } catch (Throwable $exception) {
            // Başarısız bir deneme müşteriyi pencere boyunca kilitlememeli.
            $this->idempotency?->release($this->config->bank, $data->orderId);
            $this->dispatch(PaymentFailed::from($this->config->bank, $data->orderId, $exception));

            throw $exception;
        }

        $this->dispatch(PaymentInitiated::from($this->config->bank, $data, $response));

        return $response;
    }

    /**
     * Bankaya özel ödeme başlatma.
     *
     * 3D modellerinde bankanın 3D geçidine POST edilecek form döner;
     * non-secure modelde provizyon doğrudan yapılır.
     */
    protected function performCreatePayment(CreatePaymentData $data): PaymentResponse
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
     * Banka dönüşünü doğrular; event yayınını ve hata sarmalamayı yönetir.
     *
     * Bankaya özel davranış için `performVerify()` override edilir.
     */
    final public function verify(VerifyPaymentData $data): VerificationResponse
    {
        try {
            $response = $this->performVerify($data);
        } catch (Throwable $exception) {
            $this->dispatch(PaymentFailed::from(
                $this->config->bank,
                $this->extractOrderId($data->payload),
                $exception,
            ));

            throw $exception;
        }

        $this->dispatch(PaymentVerified::from(
            $this->config->bank,
            $response,
            $this->extractOrderId($data->payload),
        ));

        return $response;
    }

    /**
     * Bankaya özel dönüş doğrulaması ve provizyon.
     */
    protected function performVerify(VerifyPaymentData $data): VerificationResponse
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
     * İade işlemi; event yayınını ve hata sarmalamayı yönetir.
     *
     * Bankaya özel davranış için `performRefund()` override edilir.
     */
    final public function refund(RefundPaymentData $data): RefundResponse
    {
        try {
            $response = $this->performRefund($data);
        } catch (Throwable $exception) {
            $this->dispatch(PaymentFailed::from($this->config->bank, $data->paymentId, $exception));

            throw $exception;
        }

        $this->dispatch(RefundIssued::from($this->config->bank, $data, $response));

        return $response;
    }

    /**
     * Ödeme verisindeki tekrarlayan ödeme planını okur.
     */
    protected function recurringPlan(CreatePaymentData $data): ?RecurringPlan
    {
        $plan = $data->metadata['recurring'] ?? null;

        return $plan instanceof RecurringPlan ? $plan : null;
    }

    /**
     * Ön provizyon alır.
     *
     * Akış normal ödemeyle aynıdır; yalnızca işlem tipi değişir. Bu yüzden
     * driver'lar `txType()` metodunu override ederek ayrımı yapar.
     */
    public function preAuthorize(CreatePaymentData $data): PaymentResponse
    {
        return $this->createPayment($data->asPreAuthorization());
    }

    /**
     * Bankaya özel iade. Alt sınıf desteklemiyorsa istisna fırlatır.
     */
    protected function performRefund(RefundPaymentData $data): RefundResponse
    {
        throw new UnsupportedOperationException('refund', $this->config->bank);
    }

    /**
     * Event yayınlar. Yapılandırmadan kapatılabilir.
     */
    protected function dispatch(object $event): void
    {
        if ((bool) config('anadolupay.events.enabled', true)) {
            Event::dispatch($event);
        }
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
     * Çoğu banka kullanılan modeli dönüşte geri gönderir (NestPay
     * `storetype`, PayFor/InterPos `SecureType`, Akbank `paymentModel`);
     * bu driver'lar metodu kendi alan adlarıyla override eder.
     *
     * Modeli bildirmeyen bankalar için en güvenli varsayım klasik 3D
     * Secure'dur: provizyon isteği fazladan gönderilirse banka mükerrer
     * işlemi reddeder, gönderilmezse ödeme sessizce alınmamış olur.
     *
     * @param  array<string, mixed>  $payload
     */
    protected function paymentModelOf(array $payload): string
    {
        return CreatePaymentData::MODEL_3D_SECURE;
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
     * Bankanın beklediği tutar biçimi.
     *
     * Varsayılan iki ondalıklı gösterimdir; kuruş cinsinden tam sayı veya
     * doğal gösterim isteyen bankalar bu metodu override eder.
     */
    protected function formatAmount(Money $money): string
    {
        return $money->toDecimalString();
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
