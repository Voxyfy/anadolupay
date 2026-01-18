<?php

declare(strict_types=1);

namespace Voxyfy\AnadoluPay\DTO;

use Voxyfy\AnadoluPay\Enums\PaymentStatus;

/**
 * Ödeme Doğrulama Yanıtı DTO
 *
 * 3D Secure işlemi sonrası yapılan doğrulama isteğinin
 * sonucunu içerir. Ödemenin başarılı olup olmadığını,
 * işlem detaylarını ve varsa hata bilgilerini barındırır.
 *
 * @property-read bool $success İşlemin başarılı olup olmadığı
 * @property-read PaymentStatus $status Ödeme durumu
 * @property-read string|null $transactionId Sağlayıcının işlem ID'si
 * @property-read string|null $orderId Sipariş ID'si
 * @property-read float|null $amount İşlem tutarı
 * @property-read string|null $currency Para birimi
 * @property-read string|null $authCode Banka onay kodu
 * @property-read string|null $hostReference Banka referans numarası
 * @property-read array $providerResponse Sağlayıcının ham yanıtı
 * @property-read string|null $errorCode Hata kodu
 * @property-read string|null $errorMessage Hata mesajı
 */
readonly class VerificationResponse
{
    /**
     * Yeni bir VerificationResponse instance'ı oluşturur.
     *
     * @param bool $success İşlem başarılı mı
     * @param PaymentStatus $status Ödeme durumu
     * @param string|null $transactionId Sağlayıcının işlem tanımlayıcısı
     * @param string|null $orderId Sipariş ID'si
     * @param float|null $amount İşlem tutarı
     * @param string|null $currency Para birimi
     * @param string|null $authCode Banka onay kodu
     * @param string|null $hostReference Banka referans numarası
     * @param array<string, mixed> $providerResponse Sağlayıcının orijinal yanıtı
     * @param string|null $errorCode Hata kodu (başarısızlık durumunda)
     * @param string|null $errorMessage Hata mesajı (başarısızlık durumunda)
     */
    public function __construct(
        public bool $success,
        public PaymentStatus $status,
        public ?string $transactionId = null,
        public ?string $orderId = null,
        public ?float $amount = null,
        public ?string $currency = null,
        public ?string $authCode = null,
        public ?string $hostReference = null,
        public array $providerResponse = [],
        public ?string $errorCode = null,
        public ?string $errorMessage = null,
    ) {}

    /**
     * Başarılı bir doğrulama yanıtı oluşturur.
     *
     * @param string $transactionId İşlem ID'si
     * @param string $orderId Sipariş ID'si
     * @param float $amount İşlem tutarı
     * @param string $currency Para birimi
     * @param string|null $authCode Banka onay kodu
     * @param string|null $hostReference Banka referans numarası
     * @param array<string, mixed> $providerResponse Sağlayıcı yanıtı
     * @return self
     */
    public static function success(
        string $transactionId,
        string $orderId,
        float $amount,
        string $currency = 'TRY',
        ?string $authCode = null,
        ?string $hostReference = null,
        array $providerResponse = [],
    ): self {
        return new self(
            success: true,
            status: PaymentStatus::COMPLETED,
            transactionId: $transactionId,
            orderId: $orderId,
            amount: $amount,
            currency: $currency,
            authCode: $authCode,
            hostReference: $hostReference,
            providerResponse: $providerResponse,
        );
    }

    /**
     * Başarısız bir doğrulama yanıtı oluşturur.
     *
     * @param string $errorMessage Hata mesajı
     * @param string|null $errorCode Hata kodu
     * @param string|null $transactionId İşlem ID'si (varsa)
     * @param string|null $orderId Sipariş ID'si (varsa)
     * @param array<string, mixed> $providerResponse Sağlayıcı yanıtı
     * @return self
     */
    public static function failed(
        string $errorMessage,
        ?string $errorCode = null,
        ?string $transactionId = null,
        ?string $orderId = null,
        array $providerResponse = [],
    ): self {
        return new self(
            success: false,
            status: PaymentStatus::FAILED,
            transactionId: $transactionId,
            orderId: $orderId,
            errorCode: $errorCode,
            errorMessage: $errorMessage,
            providerResponse: $providerResponse,
        );
    }

    /**
     * Beklemede olan bir doğrulama yanıtı oluşturur.
     * Bazı sağlayıcılar ödemeyi asenkron olarak işler.
     *
     * @param string $transactionId İşlem ID'si
     * @param string $orderId Sipariş ID'si
     * @param array<string, mixed> $providerResponse Sağlayıcı yanıtı
     * @return self
     */
    public static function pending(
        string $transactionId,
        string $orderId,
        array $providerResponse = [],
    ): self {
        return new self(
            success: true,
            status: PaymentStatus::PROCESSING,
            transactionId: $transactionId,
            orderId: $orderId,
            providerResponse: $providerResponse,
        );
    }

    /**
     * VerificationResponse'u array'e dönüştürür.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'success' => $this->success,
            'status' => $this->status->value,
            'transaction_id' => $this->transactionId,
            'order_id' => $this->orderId,
            'amount' => $this->amount,
            'currency' => $this->currency,
            'auth_code' => $this->authCode,
            'host_reference' => $this->hostReference,
            'provider_response' => $this->providerResponse,
            'error_code' => $this->errorCode,
            'error_message' => $this->errorMessage,
        ];
    }

    /**
     * Ödemenin tamamlanıp tamamlanmadığını kontrol eder.
     *
     * @return bool
     */
    public function isCompleted(): bool
    {
        return $this->status === PaymentStatus::COMPLETED;
    }

    /**
     * Ödemenin beklemede olup olmadığını kontrol eder.
     *
     * @return bool
     */
    public function isPending(): bool
    {
        return $this->status === PaymentStatus::PROCESSING || $this->status === PaymentStatus::PENDING;
    }
}
