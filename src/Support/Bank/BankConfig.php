<?php

declare(strict_types=1);

namespace Voxyfy\AnadoluPay\Support\Bank;

use Voxyfy\AnadoluPay\Exceptions\PaymentFailedException;

/**
 * Banka Sanal POS Yapılandırması
 *
 * Bir bankanın üye işyeri kimlik bilgilerini ve uç noktalarını taşır.
 * Yapılandırma `config/anadolupay.php` içindeki `banks` anahtarından okunur.
 */
final readonly class BankConfig
{
    /**
     * @param  string  $bank  Banka anahtarı (örn: garanti, akbank)
     * @param  string  $merchantId  Üye işyeri numarası (ClientId / MerchantId / ShopCode)
     * @param  string  $terminalId  Terminal numarası
     * @param  string  $username  API kullanıcı adı
     * @param  string  $password  API şifresi
     * @param  string  $secretKey  Hash/store key (3D anahtarı)
     * @param  string|null  $refundPassword  İade/iptal için ayrı şifre (Garanti)
     * @param  array<string, string>  $endpoints  payment_api, gateway_3d, gateway_3d_host, query_api
     * @param  string  $lang  Banka ödeme sayfası dili
     * @param  bool  $testMode  Test ortamı mı
     * @param  bool  $verifyHash  Dönüş hash'i doğrulansın mı
     * @param  array<string, mixed>  $extra  Bankaya özel ek ayarlar
     */
    public function __construct(
        public string $bank,
        public string $merchantId = '',
        public string $terminalId = '',
        public string $username = '',
        public string $password = '',
        public string $secretKey = '',
        public ?string $refundPassword = null,
        public array $endpoints = [],
        public string $lang = 'tr',
        public bool $testMode = false,
        public bool $verifyHash = true,
        public array $extra = [],
    ) {}

    /**
     * Yapılandırma dizisinden nesne üretir.
     *
     * @param  array<string, mixed>  $config
     */
    public static function fromArray(string $bank, array $config): self
    {
        $endpoints = $config['endpoints'] ?? [];

        return new self(
            bank: $bank,
            merchantId: (string) ($config['merchant_id'] ?? ''),
            terminalId: (string) ($config['terminal_id'] ?? ''),
            username: (string) ($config['username'] ?? ''),
            password: (string) ($config['password'] ?? ''),
            secretKey: (string) ($config['secret_key'] ?? ''),
            refundPassword: isset($config['refund_password']) ? (string) $config['refund_password'] : null,
            endpoints: is_array($endpoints) ? array_map(strval(...), $endpoints) : [],
            lang: (string) ($config['lang'] ?? 'tr'),
            testMode: (bool) ($config['test_mode'] ?? false),
            verifyHash: (bool) ($config['verify_hash'] ?? true),
            extra: is_array($config['extra'] ?? null) ? $config['extra'] : [],
        );
    }

    /**
     * Adı verilen uç noktayı döndürür.
     *
     * @param  string  $name  payment_api | gateway_3d | gateway_3d_host | query_api
     *
     * @throws PaymentFailedException Uç nokta tanımlı değilse
     */
    public function endpoint(string $name): string
    {
        $url = $this->endpoints[$name] ?? null;

        if (! is_string($url) || $url === '') {
            throw new PaymentFailedException(
                message: "'{$this->bank}' bankası için '{$name}' uç noktası tanımlı değil.",
                context: ['bank' => $this->bank, 'defined' => array_keys($this->endpoints)],
            );
        }

        return $url;
    }

    /**
     * İade/iptal şifresini döndürür; tanımlı değilse normal şifreye düşer.
     */
    public function refundPassword(): string
    {
        return $this->refundPassword ?? $this->password;
    }

    /**
     * Bankaya özel ek ayarı okur.
     */
    public function extra(string $key, mixed $default = null): mixed
    {
        return $this->extra[$key] ?? $default;
    }

    /**
     * Zorunlu kimlik alanlarının dolu olduğunu doğrular.
     *
     * @param  list<string>  $fields  Örn: ['merchantId', 'terminalId', 'secretKey']
     *
     * @throws PaymentFailedException Alan boşsa
     */
    public function require(array $fields): void
    {
        $missing = [];

        foreach ($fields as $field) {
            /** @var string $value */
            $value = $this->{$field} ?? '';

            if ($value === '') {
                $missing[] = $field;
            }
        }

        if ($missing !== []) {
            throw new PaymentFailedException(
                message: sprintf(
                    "'%s' bankası için eksik yapılandırma: %s.",
                    $this->bank,
                    implode(', ', $missing),
                ),
                context: ['bank' => $this->bank, 'missing' => $missing],
            );
        }
    }
}
