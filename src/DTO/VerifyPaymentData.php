<?php

declare(strict_types=1);

namespace Voxyfy\AnadoluPay\DTO;

/**
 * Ödeme Doğrulama Verisi DTO
 *
 * 3D Secure işlemi tamamlandıktan sonra ödemenin doğrulanması
 * için gerekli verileri içerir. Callback URL'e gelen parametreler
 * bu DTO ile işlenir.
 *
 * @property-read string $transactionId Sağlayıcının işlem ID'si
 * @property-read string $orderId Sipariş tanımlayıcısı
 * @property-read array $callbackData Callback'ten gelen ham veriler
 */
readonly class VerifyPaymentData
{
    /**
     * Yeni bir VerifyPaymentData instance'ı oluşturur.
     *
     * @param string $transactionId Sağlayıcının işlem tanımlayıcısı (zorunlu)
     * @param string $orderId Sipariş ID'si (zorunlu)
     * @param array<string, mixed> $callbackData Callback'ten gelen ham veriler
     */
    public function __construct(
        public string $transactionId,
        public string $orderId,
        public array $callbackData = [],
    ) {}

    /**
     * Array'den VerifyPaymentData oluşturur.
     *
     * @param array<string, mixed> $data Doğrulama verileri
     * @return self
     */
    public static function fromArray(array $data): self
    {
        return new self(
            transactionId: $data['transaction_id'] ?? $data['transactionId'],
            orderId: $data['order_id'] ?? $data['orderId'],
            callbackData: $data['callback_data'] ?? $data['callbackData'] ?? $data,
        );
    }

    /**
     * Callback isteğinden (POST/GET) VerifyPaymentData oluşturur.
     *
     * @param array<string, mixed> $requestData İstek verileri ($_POST veya $_GET)
     * @param string $transactionIdKey Sağlayıcıya özel transaction ID anahtarı
     * @param string $orderIdKey Sağlayıcıya özel order ID anahtarı
     * @return self
     */
    public static function fromCallback(
        array $requestData,
        string $transactionIdKey = 'transaction_id',
        string $orderIdKey = 'order_id',
    ): self {
        return new self(
            transactionId: $requestData[$transactionIdKey] ?? '',
            orderId: $requestData[$orderIdKey] ?? '',
            callbackData: $requestData,
        );
    }

    /**
     * VerifyPaymentData'yı array'e dönüştürür.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'transaction_id' => $this->transactionId,
            'order_id' => $this->orderId,
            'callback_data' => $this->callbackData,
        ];
    }

    /**
     * Callback verisinden belirli bir değer alır.
     *
     * @param string $key Anahtar
     * @param mixed $default Varsayılan değer
     * @return mixed
     */
    public function getCallbackValue(string $key, mixed $default = null): mixed
    {
        return $this->callbackData[$key] ?? $default;
    }

    /**
     * Callback verisinin belirli bir anahtarı içerip içermediğini kontrol eder.
     *
     * @param string $key Anahtar
     * @return bool
     */
    public function hasCallbackValue(string $key): bool
    {
        return array_key_exists($key, $this->callbackData);
    }
}
